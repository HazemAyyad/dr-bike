<?php

namespace App\Console\Commands;

use App\Models\AccountingJournalEntry;
use App\Models\MaintenancePayment;
use App\Services\AccountingProjectionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;

class SyncMaintenancePrepayments extends Command
{
    protected $signature = 'accounting:sync-maintenance-prepayments
        {--dry-run : Inspect classified maintenance prepayments without writing journals}';

    protected $description = 'Idempotently project safely classified maintenance prepayments into customer deposits';

    public function handle(AccountingProjectionService $projection): int
    {
        if (! Schema::hasTable('maintenance_payments')
            || ! Schema::hasColumn('maintenance_payments', 'payment_stage')) {
            $this->error('The maintenance payment stage migration is not applied.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $summary = [
            'total' => 0,
            'already_posted' => 0,
            'projected' => 0,
            'failed' => 0,
        ];
        $rows = [];

        MaintenancePayment::query()
            ->where('payment_stage', MaintenancePayment::STAGE_PRE_DELIVERY)
            ->orderBy('id')
            ->chunkById(200, function ($payments) use ($projection, $dryRun, &$summary, &$rows) {
                foreach ($payments as $payment) {
                    $summary['total']++;
                    $alreadyPosted = AccountingJournalEntry::query()
                        ->where('source_key', 'maintenance_payment:'.$payment->id.':deposit')
                        ->where('status', AccountingJournalEntry::STATUS_POSTED)
                        ->whereNull('reverses_entry_id')
                        ->exists();

                    if ($alreadyPosted) {
                        $summary['already_posted']++;
                        $status = 'already_posted';
                    } elseif ($dryRun) {
                        $status = 'ready';
                    } else {
                        try {
                            $entry = $projection->syncOrFail($payment);
                            $status = $entry ? 'projected' : 'skipped';
                            if ($entry) {
                                $summary['projected']++;
                            }
                        } catch (Throwable $e) {
                            $projection->recordFailureFor($payment, $e);
                            $summary['failed']++;
                            $status = 'failed: '.mb_substr($e->getMessage(), 0, 160);
                        }
                    }

                    $rows[] = [
                        $payment->id,
                        $payment->maintenance_id,
                        number_format((float) $payment->amount, 2, '.', ''),
                        $payment->currency,
                        $payment->box_id ?: '-',
                        $status,
                    ];
                }
            });

        if ($rows !== []) {
            $this->table(['Payment', 'Maintenance', 'Amount', 'Currency', 'Box', 'Status'], $rows);
        }
        $this->table(['Summary', 'Count'], [
            ['Total classified prepayments', $summary['total']],
            ['Already posted', $summary['already_posted']],
            [$dryRun ? 'Ready to project' : 'Projected', $dryRun
                ? $summary['total'] - $summary['already_posted']
                : $summary['projected']],
            ['Failed', $summary['failed']],
        ]);

        return $summary['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
