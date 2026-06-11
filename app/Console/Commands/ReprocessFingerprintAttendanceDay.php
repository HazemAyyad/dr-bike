<?php

namespace App\Console\Commands;

use App\Models\EmployeeAttendance;
use App\Models\EmployeeAttendanceScan;
use App\Models\EmployeeDetail;
use App\Models\EmployeeDeviceMapping;
use App\Models\FingerprintDeviceUser;
use App\Models\FingerprintRawLog;
use App\Services\FingerprintAttendanceProcessor;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReprocessFingerprintAttendanceDay extends Command
{
    protected $signature = 'fingerprint:reprocess-day
                            {--date= : Work date Y-m-d (required)}
                            {--pin= : Device user PIN (optional, all PINs if omitted)}
                            {--dry-run : Show actions without writing}';

    protected $description = 'Rebuild fingerprint attendance scans for a day from raw logs (fixes direction errors)';

    public function handle(FingerprintAttendanceProcessor $processor): int
    {
        $date = $this->option('date');
        if (! $date || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $this->error('Provide --date=Y-m-d');

            return self::FAILURE;
        }

        $pin = $this->option('pin') !== null ? trim((string) $this->option('pin')) : null;
        $dryRun = (bool) $this->option('dry-run');

        $rawQuery = FingerprintRawLog::query()
            ->whereDate('scan_time', $date)
            ->whereIn('status', ['0', '1'])
            ->orderBy('scan_time')
            ->orderBy('id');

        if ($pin !== null && $pin !== '') {
            $rawQuery->where('device_user_id', $pin);
        }

        $rawLogs = $rawQuery->get();
        if ($rawLogs->isEmpty()) {
            $this->warn("No fingerprint raw logs for {$date}".($pin ? " PIN {$pin}" : '').'.');

            return self::SUCCESS;
        }

        $rawIds = $rawLogs->pluck('id');
        $employeeIds = $this->resolveEmployeeIdsFromRawLogs($rawLogs);

        $this->info(sprintf(
            'Date %s: %d raw log(s), %d employee(s) affected%s.',
            $date,
            $rawLogs->count(),
            $employeeIds->count(),
            $dryRun ? ' [DRY RUN]' : ''
        ));

        if ($dryRun) {
            foreach ($rawLogs as $log) {
                $dir = \App\Support\FingerprintAttendanceLogFilter::directionFromDeviceStatus($log->status) ?? '?';
                $this->line("  raw #{$log->id} PIN {$log->device_user_id} {$log->scan_time} status={$log->status} => {$dir}");
            }

            return self::SUCCESS;
        }

        DB::transaction(function () use ($rawIds, $rawLogs, $employeeIds, $date, $processor) {
            foreach ($employeeIds as $employeeId) {
                EmployeeAttendanceScan::query()
                    ->where('employee_id', $employeeId)
                    ->whereDate('work_date', $date)
                    ->delete();

                EmployeeAttendance::query()
                    ->where('employee_id', $employeeId)
                    ->whereDate('date', $date)
                    ->where('source', 'fingerprint')
                    ->delete();
            }

            FingerprintRawLog::query()
                ->whereIn('id', $rawIds)
                ->update([
                    'processing_status' => 'pending',
                    'processing_error' => null,
                    'processed_at' => null,
                ]);

            foreach ($rawLogs as $rawLog) {
                $processor->processRawLog($rawLog->fresh());
            }
        });

        $this->info('Reprocessing complete.');

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, FingerprintRawLog>  $rawLogs
     * @return Collection<int, int>
     */
    protected function resolveEmployeeIdsFromRawLogs(Collection $rawLogs): Collection
    {
        $ids = collect();

        foreach ($rawLogs as $log) {
            $pin = trim((string) $log->device_user_id);
            if ($pin === '') {
                continue;
            }

            $mapping = EmployeeDeviceMapping::query()
                ->where('attendance_device_id', $log->attendance_device_id)
                ->where('device_user_id', $pin)
                ->where('enabled', true)
                ->first();
            if ($mapping) {
                $ids->push((int) $mapping->employee_id);

                continue;
            }

            $fdu = FingerprintDeviceUser::query()
                ->where('attendance_device_id', $log->attendance_device_id)
                ->where('device_user_id', $pin)
                ->first();
            if ($fdu?->linked_employee_id) {
                $ids->push((int) $fdu->linked_employee_id);

                continue;
            }

            $emp = EmployeeDetail::query()->where('device_user_id', $pin)->value('id');
            if ($emp) {
                $ids->push((int) $emp);
            }
        }

        return $ids->unique()->values();
    }
}
