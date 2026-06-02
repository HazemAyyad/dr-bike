<?php

namespace App\Console\Commands;

use App\Models\FingerprintRawLog;
use App\Services\FingerprintAttendanceProcessor;
use Illuminate\Console\Command;

class ProcessFingerprintRawLogs extends Command
{
    protected $signature = 'fingerprint:process-pending {--limit=200}';

    protected $description = 'Process pending fingerprint_raw_logs into employee attendance scans';

    public function handle(FingerprintAttendanceProcessor $processor): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $logs = FingerprintRawLog::query()
            ->where('processing_status', 'pending')
            ->orderBy('scan_time')
            ->limit($limit)
            ->get();

        $processed = 0;
        foreach ($logs as $log) {
            $processor->processRawLog($log);
            $processed++;
        }

        $this->info("Processed {$processed} pending fingerprint log(s).");

        return self::SUCCESS;
    }
}
