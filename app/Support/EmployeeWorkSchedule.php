<?php

namespace App\Support;

use App\Models\EmployeeDetail;
use App\Services\AttendanceSalaryService;
use Carbon\Carbon;

final class EmployeeWorkSchedule
{
    public const TIMEZONE = 'Asia/Hebron';

    public static function isWithin(EmployeeDetail $employee, ?Carbon $at = null): bool
    {
        $now = ($at ?? Carbon::now(self::TIMEZONE))->copy()->timezone(self::TIMEZONE);
        $startValue = trim((string) ($employee->start_work_time ?? ''));
        $endValue = trim((string) ($employee->end_work_time ?? ''));

        if ($startValue === '' || $endValue === '') {
            return false;
        }

        $start = Carbon::parse($now->toDateString().' '.$startValue, self::TIMEZONE);
        $end = Carbon::parse($now->toDateString().' '.$endValue, self::TIMEZONE);
        $shiftDate = $now->copy();

        if ($end->lte($start)) {
            $end->addDay();
            if ($now->lt($start)) {
                $start->subDay();
                $end->subDay();
                $shiftDate->subDay();
            }
        }

        $daysOff = app(AttendanceSalaryService::class)->effectiveWeeklyDaysOff($employee);
        if (in_array(strtolower($shiftDate->format('l')), $daysOff, true)) {
            return false;
        }

        return $now->gte($start) && $now->lt($end);
    }
}
