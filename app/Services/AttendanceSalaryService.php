<?php

namespace App\Services;

use App\Models\EmployeeAttendance;
use App\Models\EmployeeAttendanceScan;
use App\Models\EmployeeDetail;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

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
     * Weekly days off applied for required-day math and reporting when DB is empty.
     *
     * @return list<string>
     */
    public function effectiveWeeklyDaysOff(?EmployeeDetail $employeeDetail): array
    {
        if (! $employeeDetail) {
            return ['saturday', 'sunday'];
        }

        $weeklyDaysOff = [];
        $off = $employeeDetail->weekly_days_off;
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
            return ['saturday', 'sunday'];
        }

        return $weeklyDaysOff;
    }

    /**
     * Count contractual working days in [from, to] (inclusive), excluding weekly days off.
     */
    public function countEmployeeWorkingDaysBetween(?EmployeeDetail $employeeDetail, Carbon $from, Carbon $to): int
    {
        if (! $employeeDetail) {
            return 0;
        }

        $offs = $this->effectiveWeeklyDaysOff($employeeDetail);
        $start = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();

        if ($end->lt($start)) {
            return 0;
        }

        $count = 0;
        foreach (CarbonPeriod::create($start->toDateString(), $end->toDateString()) as $date) {
            $dayName = strtolower($date->format('l'));
            if (! in_array($dayName, $offs, true)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Worked minutes for one employee in [from, to] (inclusive), preferring scan-based totals;
     * legacy {@see EmployeeAttendance} rows are used only on days without scans.
     */
    public function sumWorkedMinutesBetween(int $employeeId, Carbon $from, Carbon $to): int
    {
        $map = $this->sumWorkedMinutesForEmployeesBetween([$employeeId], $from, $to);

        return (int) ($map[$employeeId] ?? 0);
    }

    /**
     * Bulk worked minutes keyed by employee_id.
     *
     * @param  array<int,int>  $employeeIds
     * @return array<int,int>
     */
    public function sumWorkedMinutesForEmployeesBetween(array $employeeIds, Carbon $from, Carbon $to): array
    {
        $employeeIds = array_values(array_unique(array_map('intval', array_filter($employeeIds))));
        if ($employeeIds === []) {
            return [];
        }

        $fromStr = $from->copy()->startOfDay()->toDateString();
        $toStr = $to->copy()->startOfDay()->toDateString();

        $minutesByEmployee = [];
        foreach ($employeeIds as $id) {
            $minutesByEmployee[$id] = 0;
        }

        /** @phpstan-ignore-next-line */
        $scans = EmployeeAttendanceScan::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('work_date', [$fromStr, $toStr])
            ->orderBy('employee_id')
            ->orderBy('work_date')
            ->orderBy('id')
            ->get();

        $scannedDatesByEmployee = [];
        foreach ($scans->groupBy('employee_id') as $eid => $group) {
            $eid = (int) $eid;
            $byDate = $group->groupBy(function ($s) {
                $wd = $s->work_date;

                return $wd instanceof Carbon ? $wd->format('Y-m-d') : Carbon::parse($wd)->format('Y-m-d');
            });
            foreach ($byDate as $dateStr => $dayScans) {
                $scannedDatesByEmployee[$eid][$dateStr] = true;
                $minutesByEmployee[$eid] += EmployeeAttendanceScan::computeWorkedMinutes($dayScans);
            }
        }

        /** @phpstan-ignore-next-line */
        $legacyRows = EmployeeAttendance::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('date', [$fromStr, $toStr])
            ->get();

        foreach ($legacyRows as $legacy) {
            $eid = (int) $legacy->employee_id;
            $dateCarbon = $legacy->date instanceof Carbon ? $legacy->date : Carbon::parse($legacy->date);
            $dateStr = $dateCarbon->format('Y-m-d');
            if (! empty($scannedDatesByEmployee[$eid][$dateStr] ?? false)) {
                continue;
            }
            $minutesByEmployee[$eid] = ($minutesByEmployee[$eid] ?? 0) + (int) ($legacy->worked_minutes ?? 0);
        }

        return $minutesByEmployee;
    }

    /**
     * @return array<string, mixed>
     */
    public function buildAttendanceReportRow(
        EmployeeDetail $employee,
        Carbon $periodStart,
        Carbon $periodEnd,
        int $workedMinutes,
        ?int $rewardMonth = null,
        ?int $rewardYear = null
    ): array {
        $requiredDays = $this->countEmployeeWorkingDaysBetween($employee, $periodStart, $periodEnd);
        $hoursPerDay = (float) ($employee->number_of_work_hours ?? 0);
        $dailyRequiredMinutes = (int) round($hoursPerDay * 60);
        $requiredMinutes = (int) ($requiredDays * $dailyRequiredMinutes);

        $workedMinutes = max(0, $workedMinutes);
        $normalMinutes = min($workedMinutes, $requiredMinutes);
        $overtimeMinutes = max(0, $workedMinutes - $requiredMinutes);

        $salary = $this->calculateSalary($employee, $normalMinutes, $overtimeMinutes);

        $weeklyOffForDisplay = $this->weeklyDaysOffStoredOrEffective($employee);

        $hourPriceRaw = $employee->hour_work_price;
        $overtimeRaw = $employee->overtime_work_price;
        $hourPrice = is_numeric($hourPriceRaw) ? (float) $hourPriceRaw : 0.0;
        $overtimePrice = is_numeric($overtimeRaw) ? (float) $overtimeRaw : 0.0;
        if ($overtimeRaw === null || $overtimePrice <= 0.0) {
            $overtimePrice = $hourPrice;
        }

        $debtsRaw = $employee->debts;
        $debts = is_numeric($debtsRaw) ? (float) $debtsRaw : 0.0;

        // Resolve points-based monthly reward; falls back to zero when no rule matches.
        $month = $rewardMonth ?? (int) $periodStart->month;
        $year = $rewardYear ?? (int) $periodStart->year;

        $pointsSummary = [
            'earned_points' => 0,
            'deducted_points' => 0,
            'net_points' => 0,
            'reward_amount' => 0.0,
            'matched_rule_id' => null,
        ];
        try {
            /** @var \App\Services\EmployeePointsService $pointsService */
            $pointsService = app(\App\Services\EmployeePointsService::class);
            $pointsSummary = $pointsService->getMonthlySummary((int) $employee->id, $year, $month);
        } catch (\Throwable $e) {
            // Keep defaults; rewards are optional.
        }

        $rewardAmount = (float) ($pointsSummary['reward_amount'] ?? 0.0);
        $finalSalary = (float) $salary['total_salary'] + $rewardAmount;

        return [
            'employee_id' => (int) $employee->id,
            'employee_name' => (string) ($employee->user?->name ?? ''),
            'weekly_days_off' => $weeklyOffForDisplay,
            'hour_work_price' => number_format($hourPrice, 2, '.', ''),
            'overtime_hour_price_effective' => number_format($overtimePrice, 2, '.', ''),
            'required_working_days' => $requiredDays,
            'required_hours' => $this->formatHours($requiredMinutes),
            'worked_hours' => $this->formatHours($workedMinutes),
            'normal_hours' => $this->formatHours($normalMinutes),
            'overtime_hours' => $this->formatHours($overtimeMinutes),
            'normal_salary' => number_format((float) $salary['normal_salary'], 2, '.', ''),
            'overtime_salary' => number_format((float) $salary['overtime_salary'], 2, '.', ''),
            'total_salary' => number_format((float) $salary['total_salary'], 2, '.', ''),
            'employee_debts' => number_format($debts, 2, '.', ''),
            'points_summary' => [
                'earned_points' => (int) ($pointsSummary['earned_points'] ?? 0),
                'deducted_points' => (int) ($pointsSummary['deducted_points'] ?? 0),
                'net_points' => (int) ($pointsSummary['net_points'] ?? 0),
                'reward_amount' => number_format($rewardAmount, 2, '.', ''),
                'matched_rule_id' => $pointsSummary['matched_rule_id'] ?? null,
                'month' => $month,
                'year' => $year,
            ],
            'reward_amount' => number_format($rewardAmount, 2, '.', ''),
            'final_salary' => number_format($finalSalary, 2, '.', ''),
        ];
    }

    /**
     * Raw stored days if any; otherwise same list used for calculations (config / weekend fallback).
     *
     * @return list<string>
     */
    public function weeklyDaysOffStoredOrEffective(EmployeeDetail $employeeDetail): array
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

        if (! empty($weeklyDaysOff)) {
            return $weeklyDaysOff;
        }

        return $this->effectiveWeeklyDaysOff($employeeDetail);
    }

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

        $requiredWorkDaysInMonth = $requiredWorkDaysInMonth ?? $this->countEmployeeWorkingDaysBetween(
            $employeeDetail,
            $month->copy()->startOfMonth()->startOfDay(),
            $month->copy()->endOfMonth()->startOfDay()
        );

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
}
