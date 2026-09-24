<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class DebtLedgerBalanceRepairService
{
    public function __construct(
        private DebtLedgerBalanceService $balances,
        private AccountingReconciliationService $reconciliation,
        private PartyAccountingTimelineService $timeline,
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
                    $this->timeline->schedule(
                        $issue['customer_id'],
                        $issue['seller_id'],
                        $issue['currency'],
                        $issue['transaction_date'],
                    );
                }
            }, 3);
        }

        $remaining = $dryRun ? $issues : $this->balances->inspect();
        $reconciliation = $dryRun ? null : $this->reconciliation->reconcile();

        return [
            'dry_run' => $dryRun,
            'items' => $issues->values()->all(),
            'summary' => [
                'total_issues' => $issues->count(),
                'affected_groups' => $groups->count(),
                'repaired_rows' => $repaired,
                'remaining_issues' => $remaining->count(),
                'accounting_mismatches' => (int) ($reconciliation['mismatch_count'] ?? 0),
            ],
            'remaining_items' => $remaining->values()->all(),
            'reconciliation' => $reconciliation,
        ];
    }
}
