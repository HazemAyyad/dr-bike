<?php

namespace App\Services;

use App\Models\EmployeeAttendance;
use App\Models\EmployeeDetail;
use Carbon\Carbon;

class AttendanceSalaryService
{
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

        $configuredDays = config('attendance.required_work_days_in_month');
        $requiredWorkDaysInMonth = $requiredWorkDaysInMonth ?? (is_numeric($configuredDays) ? (int) $configuredDays : 0);

        if ($requiredWorkDaysInMonth <= 0) {
            $requiredWorkDaysInMonth = $this->countConfiguredWorkdaysInMonth($month);
        }

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

    private function countConfiguredWorkdaysInMonth(Carbon $month): int
    {
        $workdays = config('attendance.workdays');
        if (! is_array($workdays) || empty($workdays)) {
            // Default: Mon-Fri (Carbon: 1..5)
            $workdays = [Carbon::MONDAY, Carbon::TUESDAY, Carbon::WEDNESDAY, Carbon::THURSDAY, Carbon::FRIDAY];
        }

        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();

        $count = 0;
        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            if (in_array($d->dayOfWeek, $workdays, true)) {
                $count++;
            }
        }

        return $count;
    }
}

