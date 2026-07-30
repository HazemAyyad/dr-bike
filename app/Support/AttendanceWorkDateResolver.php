<?php

namespace App\Support;

use App\Models\EmployeeAttendanceScan;
use Carbon\Carbon;

final class AttendanceWorkDateResolver
{
    public const AFTER_MIDNIGHT_CHECKOUT_CUTOFF_HOUR = 5;

    public static function defaultWorkDate(Carbon $scanAt): string
    {
        return $scanAt->toDateString();
    }

    public static function workDateForPossibleCheckout(int $employeeId, Carbon $scanAt): string
    {
        $workDate = self::defaultWorkDate($scanAt);

        if (! self::isBeforeCheckoutCutoff($scanAt)) {
            return $workDate;
        }

        $previousWorkDate = $scanAt->copy()->subDay()->toDateString();

        return self::hasOpenShift($employeeId, $previousWorkDate)
            ? $previousWorkDate
            : $workDate;
    }

    public static function isBeforeCheckoutCutoff(Carbon $scanAt): bool
    {
        $hour = (int) $scanAt->format('G');

        return $hour >= 0 && $hour < self::AFTER_MIDNIGHT_CHECKOUT_CUTOFF_HOUR;
    }

    public static function hasOpenShift(int $employeeId, string $workDate): bool
    {
        $last = EmployeeAttendanceScan::query()
            ->where('employee_id', $employeeId)
            ->whereDate('work_date', $workDate)
            ->orderByDesc('id')
            ->first();

        return $last !== null && $last->direction === 'in';
    }
}
