<?php

namespace Tests\Unit;

use App\Services\AccountingReconciliationService;
use App\Services\DebtLedgerBalanceRepairService;
use App\Services\DebtLedgerBalanceService;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class DebtLedgerBalanceRepairServiceTest extends TestCase
{
    public function test_dry_run_executes_reconciliation_and_reports_its_real_mismatch_count_without_repairing(): void
    {
        $balances = Mockery::mock(DebtLedgerBalanceService::class);
        $balances->shouldReceive('inspect')->once()->andReturn(collect([$this->issue()]));
        $balances->shouldNotReceive('recalculatePersonCurrencyBalances');
        $reconciliation = Mockery::mock(AccountingReconciliationService::class);
        $reconciliation->shouldReceive('reconcile')->once()->andReturn([
            'complete' => false,
            'mismatch_count' => 7,
            'comparisons' => [],
            'mismatches' => [],
        ]);

        $result = (new DebtLedgerBalanceRepairService($balances, $reconciliation))->run(true);

        $this->assertTrue($result['dry_run']);
        $this->assertSame(7, $result['summary']['accounting_mismatches']);
        $this->assertSame(0, $result['summary']['repaired_rows']);
        $this->assertSame(1, $result['summary']['remaining_issues']);
    }

    public function test_failed_reconciliation_is_not_reported_as_zero_mismatches(): void
    {
        $balances = Mockery::mock(DebtLedgerBalanceService::class);
        $balances->shouldReceive('inspect')->once()->andReturn(collect());
        $reconciliation = Mockery::mock(AccountingReconciliationService::class);
        $reconciliation->shouldReceive('reconcile')->once()->andThrow(new RuntimeException('read-only reconciliation unavailable'));

        $result = (new DebtLedgerBalanceRepairService($balances, $reconciliation))->run(true);

        $this->assertNull($result['summary']['accounting_mismatches']);
        $this->assertSame('تعذر تنفيذ المطابقة المحاسبية؛ لم يتم افتراض أن عدد الفروقات يساوي صفرًا.', $result['reconciliation']['message']);
    }

    /** @return array<string, mixed> */
    private function issue(): array
    {
        return [
            'person_type' => 'customer',
            'person_id' => 10,
            'customer_id' => 10,
            'seller_id' => null,
            'currency' => 'شيكل',
            'transaction_date' => '2026-09-01',
            'transaction_id' => 1,
            'stored_balance' => 999,
            'expected_balance' => 100,
            'difference' => 899,
        ];
    }
}
