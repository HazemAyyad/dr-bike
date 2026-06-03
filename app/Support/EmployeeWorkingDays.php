<?php

namespace App\Support;

use App\Models\EmployeeDetail;
use App\Services\AttendanceSalaryService;
use Carbon\Carbon;

class EmployeeWorkingDays
{
    /**
     * @return list<string>
     */
    public static function weeklyDaysOff(?EmployeeDetail $employee): array
    {
        if (! $employee) {
            return app(AttendanceSalaryService::class)->effectiveWeeklyDaysOff(null);
        }

        return app(AttendanceSalaryService::class)->effectiveWeeklyDaysOff($employee);
    }

    public static function isWorkingDay(?EmployeeDetail $employee, Carbon $date): bool
    {
        $dayName = strtolower($date->timezone(EmployeeVisibleTasks::TIMEZONE)->format('l'));
        $off = array_map('strtolower', self::weeklyDaysOff($employee));

        return ! in_array($dayName, $off, true);
    }
}
