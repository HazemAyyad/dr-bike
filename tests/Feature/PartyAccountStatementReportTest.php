<?php

namespace Tests\Feature;

use App\Services\DebtAccountStatementService;
use App\Services\DebtLedgerService;
use App\Services\PartyAccountStatementReportService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PartyAccountStatementReportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.party_report_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]]);
        DB::setDefaultConnection('party_report_test');
        foreach (['incoming_checks', 'outgoing_checks'] as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('from_customer')->nullable();
                $table->unsignedBigInteger('from_seller')->nullable();
                $table->unsignedBigInteger('customer_id')->nullable();
                $table->unsignedBigInteger('seller_id')->nullable();
                $table->string('status');
                $table->string('currency');
                $table->decimal('total');
                $table->timestamps();
            });
        }
        Schema::create('instant_sales', function (Blueprint $table) {
            $table->id();
            $table->string('serial_number')->nullable();
        });
    }

    public function test_customer_statement_separates_invoice_and_receipt_and_keeps_returned_checks_informational(): void
    {
        DB::table('instant_sales')->insert(['id' => 8, 'serial_number' => 'SAL-0000008']);
        $this->statementRows(1, null, [
            $this->row(1, '2026-10-31', 'given', 200),
            $this->row(2, '2026-11-01', 'given', 100, ['entry_kind' => 'sales_invoice', 'source' => 'instant_sale', 'source_id' => 8]),
            $this->row(0, '2026-11-01', 'taken', 40, ['entry_kind' => 'receipt', 'source' => 'instant_sale', 'source_id' => 8]),
            $this->row(3, '2026-11-02', 'taken', 10, ['source' => 'sales_return']),
        ]);
        DB::table('incoming_checks')->insert([
            ['from_customer' => 1, 'status' => 'returned', 'currency' => 'شيكل', 'total' => 70, 'updated_at' => '2026-11-02 12:00:00'],
            ['from_customer' => 1, 'status' => 'returned', 'currency' => 'دولار', 'total' => 900, 'updated_at' => '2026-11-02 12:00:00'],
            ['from_customer' => 2, 'status' => 'returned', 'currency' => 'شيكل', 'total' => 900, 'updated_at' => '2026-11-02 12:00:00'],
            ['from_customer' => 1, 'status' => 'returned', 'currency' => 'شيكل', 'total' => 900, 'updated_at' => '2026-10-31 12:00:00'],
        ]);

        $report = app(PartyAccountStatementReportService::class)->report($this->filters('customer', 1));

        $this->assertSame(['opening_balance' => 200.0, 'debit' => 100.0, 'credit' => 50.0, 'closing_balance' => 250.0, 'movement_count' => 3], $report['summary']);
        $this->assertSame(['SAL-0000008', 'SAL-0000008', '3'], array_column($report['rows'], 'number'));
        $this->assertSame(['فاتورة مبيعات', 'سند قبض', 'مردودات المبيعات'], array_column($report['rows'], 'description'));
        $this->assertSame([300.0, 260.0, 250.0], array_column($report['rows'], 'balance'));
        $this->assertSame(['incoming' => 70.0, 'outgoing' => 0.0], $report['returned_checks']);
        $this->assertSame('ملاحظة', $report['rows'][0]['notes']);
    }

    public function test_supplier_credit_balance_and_period_only_option_keep_the_same_movements(): void
    {
        $this->statementRows(null, 1, [
            $this->row(1, '2026-10-31', 'taken', 300),
            $this->row(2, '2026-11-01', 'taken', 120, ['entry_kind' => 'purchase_invoice', 'source' => 'purchase_invoice', 'source_id' => 9]),
            $this->row(3, '2026-11-02', 'given', 20, ['entry_kind' => 'payment']),
        ]);
        $service = app(PartyAccountStatementReportService::class);
        $filters = $this->filters('seller', 1);
        $full = $service->report($filters);
        $period = $service->report($filters + ['include_opening' => 0, 'include_returned_checks' => 0]);

        $this->assertSame(-400.0, $full['summary']['closing_balance']);
        $this->assertSame(-100.0, $period['summary']['closing_balance']);
        $this->assertSame(['فاتورة مشتريات', 'سند دفع'], array_column($period['rows'], 'description'));
        $this->assertSame('9', $period['rows'][0]['number']);
        $this->assertSame(120.0, $period['summary']['credit']);
        $this->assertSame([], $period['returned_checks']);
    }

    private function statementRows(?int $customer, ?int $seller, array $rows): void
    {
        $this->mock(DebtAccountStatementService::class)->shouldReceive('rows')
            ->with($customer, $seller, 'شيكل', null, '2026-11-11')->andReturn(collect($rows));
        $ledger = $this->partialMock(DebtLedgerService::class);
        $ledger->shouldReceive('getPersonInfo')->with($customer, $seller)->andReturn(['id' => 1, 'name' => 'الحساب']);
    }

    private function filters(string $type, int $id): array
    {
        return ['person_type' => $type, 'person_id' => $id, 'currency' => 'شيكل', 'from_date' => '2026-11-01', 'to_date' => '2026-11-11'];
    }

    private function row(int $id, string $date, string $type, float $amount, array $extra = []): array
    {
        return $extra + ['id' => $id, 'transaction_date' => $date, 'type' => $type, 'amount' => $amount,
            'entry_kind' => $type === 'given' ? 'payment' : 'receipt', 'source' => 'manual', 'source_id' => null, 'note' => 'ملاحظة'];
    }
}
