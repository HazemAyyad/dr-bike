<?php

namespace App\Console\Commands;

use App\Services\DebtLedgerBalanceRepairService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class CheckDebtLedgerBalances extends Command
{
    protected $signature = 'debt-ledger:check-balances
        {--dry-run : Explicitly run the read-only check}
        {--repair : Repair only the derived balance_after field}';

    protected $description = 'Check or safely repair Debt Ledger running balances';

    public function handle(DebtLedgerBalanceRepairService $repair): int
    {
        if (! Schema::hasTable('debt_transactions')) {
            $this->error('The debt_transactions table is not available.');

            return self::FAILURE;
        }
        if ($this->option('dry-run') && $this->option('repair')) {
            $this->error('Choose either --dry-run or --repair, not both.');

            return self::INVALID;
        }

        $result = $repair->run(! $this->option('repair'));
        $issues = collect($result['items']);
        $this->table(
            ['Person type', 'Person ID', 'Currency', 'Transaction', 'Stored', 'Expected', 'Difference'],
            $issues->map(fn (array $issue) => [
                $issue['person_type'],
                $issue['person_id'],
                $issue['currency'],
                $issue['transaction_id'],
                number_format($issue['stored_balance'], 2, '.', ''),
                number_format($issue['expected_balance'], 2, '.', ''),
                number_format($issue['difference'], 2, '.', ''),
            ])->all(),
        );

        if ($this->option('repair')) {
            $this->info('Repaired balance_after rows: '.$result['summary']['repaired_rows']);
            $this->line('Remaining running-balance issues: '.$result['summary']['remaining_issues']);
            $this->line('Accounting reconciliation mismatches: '.$result['summary']['accounting_mismatches']);
        } else {
            $this->warn('READ ONLY: no debt, box, cash, or journal data was changed.');
        }

        $this->line('Running-balance issues: '.$issues->count());

        return self::SUCCESS;
    }
}
