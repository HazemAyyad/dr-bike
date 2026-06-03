<?php

namespace App\Console\Commands;

use App\Models\FingerprintRawLog;
use App\Support\FingerprintAttendanceLogFilter;
use Illuminate\Console\Command;

class PurgeFingerprintOperlogLogs extends Command
{
    protected $signature = 'fingerprint:purge-operlog {--dry-run : List only, do not update}';

    protected $description = 'Mark non-attendance OPLOG/OPERLOG rows as ignored in fingerprint_raw_logs';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $updated = 0;

        FingerprintRawLog::query()
            ->whereIn('processing_status', ['pending', 'processed', 'error'])
            ->orderBy('id')
            ->chunkById(500, function ($logs) use ($dryRun, &$updated) {
                foreach ($logs as $log) {
                    if (FingerprintAttendanceLogFilter::isAttendanceLog($log)) {
                        continue;
                    }

                    $updated++;
                    if ($dryRun) {
                        $this->line(sprintf(
                            '#%d pin=%s status=%s verify=%s',
                            $log->id,
                            $log->device_user_id,
                            $log->status,
                            $log->verify_type
                        ));
                        continue;
                    }

                    $log->processing_status = 'ignored';
                    $log->processing_error = 'operlog_not_attendance';
                    $log->processed_at = now();
                    $log->save();
                }
            });

        $this->info($dryRun
            ? "Would ignore {$updated} non-attendance log(s)."
            : "Ignored {$updated} non-attendance log(s).");

        return self::SUCCESS;
    }
}
