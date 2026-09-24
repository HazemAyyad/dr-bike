<?php

namespace App\Console\Commands;

use App\Services\MaintenancePrepaymentSyncService;
use Illuminate\Console\Command;

class SyncMaintenancePrepayments extends Command
{
    protected $signature = 'accounting:sync-maintenance-prepayments
        {--dry-run : Inspect classified maintenance prepayments without writing journals}';

    protected $description = 'Idempotently project safely classified maintenance prepayments into customer deposits';

    public function handle(MaintenancePrepaymentSyncService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        try {
            $result = $service->run($dryRun);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $summary = $result['summary'];
        $rows = collect($result['items'])->map(fn (array $item) => [
            $item['payment_id'],
            $item['maintenance_id'],
            number_format((float) $item['amount'], 2, '.', ''),
            $item['currency'],
            $item['box_id'] ?: '-',
            $item['status'].($item['message'] ? ': '.$item['message'] : ''),
        ])->all();

        if ($rows !== []) {
            $this->table(['Payment', 'Maintenance', 'Amount', 'Currency', 'Box', 'Status'], $rows);
        }
        $this->table(['Summary', 'Count'], [
            ['Total classified prepayments', $summary['total']],
            ['Already posted', $summary['already_posted']],
            ['Ready to project', $summary['ready']],
            ['Projected', $summary['projected']],
            ['Failed', $summary['failed']],
            ['Skipped/blocked', $summary['skipped']],
        ]);

        return $summary['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
