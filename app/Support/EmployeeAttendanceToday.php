<?php

namespace App\Support;

use App\Models\EmployeeAttendance;
use App\Models\EmployeeAttendanceScan;
use Illuminate\Support\Facades\Schema;

final class EmployeeAttendanceToday
{
    public const TIMEZONE = EmployeePendingTasksForToday::TIMEZONE;

    public static function todayDateString(): string
    {
        return EmployeePendingTasksForToday::todayDateString();
    }

    /** Employee scanned in (or legacy arrived) at least once today. */
    public static function hasCheckedInToday(int $employeeId): bool
    {
        $date = self::todayDateString();

        if (Schema::hasTable('employee_attendance_scans')) {
            if (EmployeeAttendanceScan::query()
                ->where('employee_id', $employeeId)
                ->whereDate('work_date', $date)
                ->where('direction', 'in')
                ->exists()) {
                return true;
            }
        }

        return EmployeeAttendance::query()
            ->where('employee_id', $employeeId)
            ->whereDate('date', $date)
            ->whereNotNull('arrived_at')
            ->exists();
    }
}
