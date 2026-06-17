<?php

namespace App\Console\Commands;

use App\Models\EmployeeDetail;
use App\Services\AdminNotificationService;
use App\Services\CronJobLogger;
use App\Support\EmployeeAttendanceToday;
use App\Support\EmployeePendingTasksForToday;
use App\Support\EmployeeWorkingDays;
use Carbon\Carbon;
use Illuminate\Console\Command;

class NotifyAbsentEmployees extends Command
{
    protected $signature = 'attendance:notify-absent-employees {--force : إرسال حتى لو أُرسل إشعار اليوم مسبقاً}';

    protected $description = 'Notify admin about fingerprint employees who have not checked in today (15:00 Palestine)';

    public function handle(
        AdminNotificationService $adminNotificationService,
        CronJobLogger $cronJobLogger
    ): int {
        $force = (bool) $this->option('force');

        return $cronJobLogger->run(
            'attendance:notify-absent-employees',
            function ($buffer, $log) use ($adminNotificationService, $force) {
                $tz = EmployeePendingTasksForToday::TIMEZONE;
                $today = Carbon::now($tz);
                $dateKey = $today->toDateString();

                $absentEmployees = [];
                $skippedOffDay = 0;
                $checkedIn = 0;

                $employees = EmployeeDetail::query()
                    ->with('user:id,name,type')
                    ->where('fingerprint_enabled', true)
                    ->whereHas('user', fn ($q) => $q->where('type', 'employee'))
                    ->get();

                foreach ($employees as $employee) {
                    if (! EmployeeWorkingDays::isWorkingDay($employee, $today)) {
                        $skippedOffDay++;

                        continue;
                    }

                    if (EmployeeAttendanceToday::hasCheckedInToday((int) $employee->id)) {
                        $checkedIn++;

                        continue;
                    }

                    $absentEmployees[] = [
                        'employee_id' => (int) $employee->id,
                        'employee_name' => (string) ($employee->user->name ?? "موظف #{$employee->id}"),
                    ];
                }

                $notification = null;
                if ($absentEmployees !== []) {
                    $notification = $adminNotificationService->notifyAbsentEmployeesReminder(
                        $dateKey,
                        $absentEmployees,
                        $force
                    );
                }

                $summary = sprintf(
                    'Date %s: %d fingerprint employee(s), %d absent, %d checked in, %d off-day.',
                    $dateKey,
                    $employees->count(),
                    count($absentEmployees),
                    $checkedIn,
                    $skippedOffDay
                );

                $log->update([
                    'payload' => array_merge($log->payload ?? [], [
                        'force' => $force,
                        'work_date' => $dateKey,
                        'absent_count' => count($absentEmployees),
                        'absent_employees' => $absentEmployees,
                        'checked_in' => $checkedIn,
                        'skipped_off_day' => $skippedOffDay,
                        'notification_id' => $notification?->id,
                    ]),
                ]);

                $this->info($summary);

                return self::SUCCESS;
            },
            'attendance:notify-absent-employees',
            [
                'timezone' => EmployeePendingTasksForToday::TIMEZONE,
                'force' => $force,
            ],
        );
    }
}
