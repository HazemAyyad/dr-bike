<?php

namespace App\Console\Commands;

use App\Models\EmployeeAttendanceScan;
use App\Models\EmployeeDetail;
use App\Services\CronJobLogger;
use App\Services\EmployeeAttendanceCheckoutService;
use App\Support\EmployeePendingTasksForToday;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AutoCheckoutOpenAttendanceShifts extends Command
{
    protected $signature = 'attendance:auto-checkout-open-shifts {--date= : Work date to close (Y-m-d), default: yesterday in Asia/Hebron}';

    protected $description = 'Auto check-out employees still marked in at end of the previous work day (runs at 01:00)';

    public function handle(
        EmployeeAttendanceCheckoutService $checkoutService,
        CronJobLogger $cronJobLogger
    ): int {
        $workDate = $this->option('date');

        return $cronJobLogger->run(
            'attendance:auto-checkout-open-shifts',
            function ($buffer, $log) use ($checkoutService, $workDate) {
                $tz = EmployeePendingTasksForToday::TIMEZONE;
                $workDate = $workDate
                    ?: Carbon::now($tz)->subDay()->toDateString();

                $checkoutAt = Carbon::parse($workDate, $tz)->endOfDay();

                $openEmployeeIds = $this->openEmployeeIdsForWorkDate($workDate);

                $closed = 0;
                $failed = 0;
                $failures = [];

                foreach ($openEmployeeIds as $employeeId) {
                    $employee = EmployeeDetail::query()->find($employeeId);
                    if (! $employee) {
                        continue;
                    }

                    try {
                        $checkoutService->checkout($employee, $checkoutAt, $workDate, 'auto');
                        $closed++;
                    } catch (\Throwable $e) {
                        $failed++;
                        $failures[] = [
                            'employee_id' => (int) $employeeId,
                            'message' => $e->getMessage(),
                        ];
                        Log::warning('attendance.auto_checkout_failed', [
                            'employee_id' => $employeeId,
                            'work_date' => $workDate,
                            'message' => $e->getMessage(),
                        ]);
                    }
                }

                $summary = sprintf(
                    'Work date %s: %d open shift(s), %d closed, %d failed.',
                    $workDate,
                    $openEmployeeIds->count(),
                    $closed,
                    $failed
                );

                $log->update([
                    'payload' => array_merge($log->payload ?? [], [
                        'work_date' => $workDate,
                        'checkout_at' => $checkoutAt->toIso8601String(),
                        'open_count' => $openEmployeeIds->count(),
                        'closed' => $closed,
                        'failed' => $failed,
                        'failures' => $failures,
                    ]),
                ]);

                $this->info($summary);

                return self::SUCCESS;
            },
            'attendance:auto-checkout-open-shifts',
            [
                'timezone' => EmployeePendingTasksForToday::TIMEZONE,
                'work_date' => $workDate,
            ],
        );
    }

    /**
     * @return \Illuminate\Support\Collection<int, int>
     */
    protected function openEmployeeIdsForWorkDate(string $workDate)
    {
        $lastScanIds = EmployeeAttendanceScan::query()
            ->select(DB::raw('MAX(id) as id'))
            ->whereDate('work_date', $workDate)
            ->groupBy('employee_id')
            ->pluck('id');

        if ($lastScanIds->isEmpty()) {
            return collect();
        }

        return EmployeeAttendanceScan::query()
            ->whereIn('id', $lastScanIds)
            ->where('direction', 'in')
            ->pluck('employee_id');
    }
}
