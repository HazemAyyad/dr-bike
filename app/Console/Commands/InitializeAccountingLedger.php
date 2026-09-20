<?php

namespace App\Console\Commands;

use App\Services\AccountingCutoverService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class InitializeAccountingLedger extends Command
{
    protected $signature = 'accounting:initialize
        {--date= : Cutover date; defaults to today}
        {--apply : Post the opening journals}
        {--force : Apply despite blocking snapshot issues}
        {--notes= : Deployment note stored with the cutover}';

    protected $description = 'Preview or apply currency-safe opening balances from current operational state';

    public function handle(AccountingCutoverService $service): int
    {
        if (! Schema::hasTable('accounting_cutovers')) {
            $this->error('Accounting migrations are not applied.');

            return self::FAILURE;
        }

        $preview = $service->preview($this->option('date'));
        $this->table(['Currency', 'Debit before equity', 'Credit before equity', 'Opening equity', 'Lines'], collect($preview['currencies'])->map(fn ($row) => [
            $row['currency'], $row['debit_before_equity'], $row['credit_before_equity'], $row['opening_equity'], $row['lines_count'],
        ])->all());
        foreach ($preview['issues'] as $issue) {
            $this->{$issue['severity'] === 'blocking' ? 'error' : 'warn'}($issue['code'].': '.$issue['message']);
        }

        if (! $this->option('apply')) {
            $this->info('Preview only. Re-run with --apply during a maintenance window after reviewing the totals.');

            return $preview['can_apply'] ? self::SUCCESS : self::FAILURE;
        }

        $cutover = $service->apply(
            $this->option('date'),
            null,
            $this->option('notes'),
            (bool) $this->option('force'),
        );
        $this->info('Applied accounting cutover #'.$cutover->id.' with '.count($cutover->journal_entry_ids ?? []).' opening journals.');

        return self::SUCCESS;
    }
}
