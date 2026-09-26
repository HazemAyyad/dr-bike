<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class DebtLedgerBalanceRepairService
{
    public function __construct(
        private DebtLedgerBalanceService $balances,
        private AccountingReconciliationService $reconciliation,
    ) {}

    /** @return array<string, mixed> */
    public function run(bool $dryRun = true): array
    {
        $issues = $this->balances->inspect();
        $groups = $issues->unique(fn (array $issue) => ($issue['customer_id'] ?: 's'.$issue['seller_id']).'|'.$issue['currency']);
        $repaired = 0;

        if (! $dryRun && $groups->isNotEmpty()) {
            DB::transaction(function () use ($groups, &$repaired) {
                foreach ($groups as $issue) {
                    $repaired += $this->balances->recalculatePersonCurrencyBalances(
                        $issue['customer_id'],
                        $issue['seller_id'],
                        $issue['currency'],
                        quiet: true,
                    );
                }
            }, 3);
        }

        $remaining = $dryRun ? $issues : $this->balances->inspect();
        $reconciliation = $this->readOnlyReconciliation();
        $accountingMismatches = array_key_exists('mismatch_count', $reconciliation)
            && $reconciliation['mismatch_count'] !== null
                ? (int) $reconciliation['mismatch_count']
                : null;

        return [
            'dry_run' => $dryRun,
            'items' => $issues->values()->all(),
            'summary' => [
                'total_issues' => $issues->count(),
                'affected_groups' => $groups->count(),
                'repaired_rows' => $repaired,
                'remaining_issues' => $remaining->count(),
                'accounting_mismatches' => $accountingMismatches,
            ],
            'remaining_items' => $remaining->values()->all(),
            'reconciliation' => $reconciliation,
        ];
    }

    /** @return array<string, mixed> */
    private function readOnlyReconciliation(): array
    {
        try {
            return $this->reconciliation->reconcile();
        } catch (Throwable $exception) {
            Log::error('debt_ledger_balance_reconciliation_failed', [
                'message' => $exception->getMessage(),
            ]);
            report($exception);

            return [
                'complete' => false,
                'mismatch_count' => null,
                'comparisons' => [],
                'mismatches' => [],
                'message' => 'تعذر تنفيذ المطابقة المحاسبية؛ لم يتم افتراض أن عدد الفروقات يساوي صفرًا.',
            ];
        }
    }
}
