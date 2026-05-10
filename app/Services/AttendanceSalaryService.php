<?php

namespace App\Services;

use App\Models\EmployeeAttendance;
use App\Models\EmployeeDetail;
use Carbon\Carbon;

class AttendanceSalaryService
{
    private const DAY_NAMES = [
        'monday',
        'tuesday',
        'wednesday',
        'thursday',
        'friday',
        'saturday',
        'sunday',
    ];

    /**
     * @return array{required_minutes:int, normal_minutes:int, overtime_minutes:int}
     */
    public function calculateDailyOvertime(?EmployeeDetail $employeeDetail, int $workedMinutes): array
    {
        $requiredMinutes = 0;
        if ($employeeDetail && $employeeDetail->number_of_work_hours !== null) {
            $requiredMinutes = (int) round(((float) $employeeDetail->number_of_work_hours) * 60);
        }

        $normalMinutes = min($workedMinutes, $requiredMinutes);
        $overtimeMinutes = max(0, $workedMinutes - $requiredMinutes);

        return [
            'required_minutes' => max(0, $requiredMinutes),
            'normal_minutes' => max(0, (int) $normalMinutes),
            'overtime_minutes' => max(0, (int) $overtimeMinutes),
        ];
    }

    /**
     * @return array{
     *   monthly_worked_minutes:int,
     *   monthly_required_minutes:int,
     *   monthly_overtime_minutes:int,
     *   required_work_days_in_month:int,
     * }
     */
    public function calculateMonthlyOvertime(EmployeeDetail $employeeDetail, Carbon $month, ?int $requiredWorkDaysInMonth = null): array
    {
        $monthStart = $month->copy()->startOfMonth()->toDateString();
        $monthEnd = $month->copy()->endOfMonth()->toDateString();

        $monthlyWorkedMinutes = (int) EmployeeAttendance::query()
            ->where('employee_id', $employeeDetail->id)
            ->whereBetween('date', [$monthStart, $monthEnd])
            ->sum('worked_minutes');

        $requiredWorkDaysInMonth = $requiredWorkDaysInMonth ?? $this->countEmployeeWorkingDaysInMonth($employeeDetail, $month);

        $hoursPerDay = (float) ($employeeDetail->number_of_work_hours ?? 0);
        $monthlyRequiredMinutes = (int) round(($hoursPerDay * $requiredWorkDaysInMonth) * 60);
        $monthlyOvertimeMinutes = max(0, $monthlyWorkedMinutes - $monthlyRequiredMinutes);

        return [
            'monthly_worked_minutes' => max(0, $monthlyWorkedMinutes),
            'monthly_required_minutes' => max(0, $monthlyRequiredMinutes),
            'monthly_overtime_minutes' => max(0, (int) $monthlyOvertimeMinutes),
            'required_work_days_in_month' => max(0, (int) $requiredWorkDaysInMonth),
        ];
    }

    /**
     * @return array{normal_salary:float, overtime_salary:float, total_salary:float}
     */
    public function calculateSalary(?EmployeeDetail $employeeDetail, int $normalMinutes, int $overtimeMinutes): array
    {
        $hourPriceRaw = $employeeDetail?->hour_work_price;
        $overtimeRaw = $employeeDetail?->overtime_work_price;

        $hourPrice = is_numeric($hourPriceRaw) ? (float) $hourPriceRaw : 0.0;
        $overtimePrice = is_numeric($overtimeRaw) ? (float) $overtimeRaw : 0.0;
        if ($overtimeRaw === null || $overtimePrice <= 0) {
            $overtimePrice = $hourPrice;
        }

        $normalSalary = ($normalMinutes / 60) * $hourPrice;
        $overtimeSalary = ($overtimeMinutes / 60) * $overtimePrice;
        $total = $normalSalary + $overtimeSalary;

        return [
            'normal_salary' => round($normalSalary, 2),
            'overtime_salary' => round($overtimeSalary, 2),
            'total_salary' => round($total, 2),
        ];
    }

    public function formatHours(int $minutes): string
    {
        return number_format(max(0, $minutes) / 60, 2, '.', '');
    }

    /**
     * Count working days in a month based on employee weekly days off.
     * Backward-compat fallback: if employee has no configuration, use config('attendance.default_weekly_days_off'),
     * and finally default to Sat/Sun.
     */
    private function countEmployeeWorkingDaysInMonth(EmployeeDetail $employeeDetail, Carbon $month): int
    {
        $off = $employeeDetail->weekly_days_off;
        $weeklyDaysOff = [];
        if (is_array($off) && ! empty($off)) {
            foreach ($off as $d) {
                if (is_string($d)) {
                    $dd = strtolower(trim($d));
                    if (in_array($dd, self::DAY_NAMES, true)) {
                        $weeklyDaysOff[] = $dd;
                    }
                }
            }
            $weeklyDaysOff = array_values(array_unique($weeklyDaysOff));
        }

        if (empty($weeklyDaysOff)) {
            $fallback = config('attendance.default_weekly_days_off');
            if (is_array($fallback) && ! empty($fallback)) {
                foreach ($fallback as $d) {
                    if (is_string($d)) {
                        $dd = strtolower(trim($d));
                        if (in_array($dd, self::DAY_NAMES, true)) {
                            $weeklyDaysOff[] = $dd;
                        }
                    }
                }
            }
        }

        if (empty($weeklyDaysOff)) {
            $weeklyDaysOff = ['saturday', 'sunday'];
        }

        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();

        $count = 0;
        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            $dayName = strtolower($d->format('l')); // Monday..Sunday
            if (! in_array($dayName, $weeklyDaysOff, true)) {
                $count++;
            }
        }

        return $count;
    }
}

