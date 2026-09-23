<?php

namespace App\Console\Commands;

use App\Services\AccountingProjectionRepairService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class RepairAccountingProjections extends Command
{
    protected $signature = 'accounting:repair-projections {--dry-run : Inspect failures without changing any data}';

    protected $description = 'Safely inspect and retry unresolved accounting projection failures';

    public function handle(AccountingProjectionRepairService $repair): int
    {
        if (! Schema::hasTable('accounting_projection_failures')) {
            $this->error('Accounting migrations are not applied.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        if ($dryRun) {
            $this->warn('DRY RUN: no source, journal, or failure record will be changed.');
        }

        $result = $repair->run($dryRun);
        $rows = collect($result['items'])->map(function (array $item) {
            $issueCodes = collect($item['issues'] ?? [])->pluck('code')->filter()->implode(', ');
            $assessment = $item['message'].($issueCodes ? ' ['.$issueCodes.']' : '');

            return [
                $item['failure_id'],
                $item['source_type'].':'.$item['source_id'],
                $item['previous_error'] ?: '-',
                $item['repairable'] ? 'YES' : 'NO',
                strtoupper($item['status']),
                $assessment,
            ];
        })->all();
        if ($rows !== []) {
            $this->table(['Failure', 'Source', 'Recorded failure', 'Repairable', 'Status', 'Current assessment'], $rows);
        }

        $summary = $result['summary'];
        $this->table(['Summary', 'Count'], [
            ['Total failures', $summary['total_failures']],
            ['Repairable', $summary['repairable']],
            ['Successfully repaired', $summary['successfully_repaired']],
            ['Still failing', $summary['still_failing']],
            ['Already valid/stale failure', $summary['already_valid_or_stale']],
            ['Skipped', $summary['skipped']],
        ]);

        return $summary['still_failing'] > 0 || $summary['skipped'] > 0
            ? self::FAILURE
            : self::SUCCESS;
    }
}
