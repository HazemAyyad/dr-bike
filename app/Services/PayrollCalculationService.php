<?php

namespace App\Services;

use App\Models\EmployeeDetail;
use App\Models\EmployeeOrder;
use App\Models\EmployeeSalaryPeriod;
use Carbon\Carbon;

class PayrollCalculationService
{
    public function __construct(private AttendanceSalaryService $salaryService) {}

    /** @return array<string, mixed> */
    public function calculate(EmployeeDetail $employee, string $month): array
    {
        $start = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $workedMinutes = $this->salaryService->sumWorkedMinutesBetween($employee->id, $start, $end);
        $attendance = $this->salaryService->buildAttendanceReportRow(
            $employee,
            $start,
            $end,
            $workedMinutes,
            (int) $start->month,
            (int) $start->year
        );

        $normal = round((float) ($attendance['normal_salary'] ?? 0), 2);
        $overtime = round((float) ($attendance['overtime_salary'] ?? 0), 2);
        $bonuses = round((float) ($attendance['reward_amount'] ?? 0), 2);
        $gross = round($normal + $overtime + $bonuses, 2);

        $advances = $this->outstandingAdvances($employee, $end);
        $availableAdvances = round((float) $advances->sum('remaining_amount'), 2);
        $advancesToApply = min($gross, $availableAdvances);

        $existing = EmployeeSalaryPeriod::query()
            ->where('employee_id', $employee->id)
            ->whereDate('salary_month', $start->toDateString())
            ->first();

        return [
            'employee_id' => (int) $employee->id,
            'employee_name' => (string) ($employee->user?->name ?? ''),
            'salary_month' => $start->format('Y-m'),
            'normal_salary' => $normal,
            'overtime_salary' => $overtime,
            'bonuses' => $bonuses,
            'gross_entitlement' => $gross,
            'available_advances' => $availableAdvances,
            'advances_to_apply' => $existing ? (float) $existing->advances_applied : $advancesToApply,
            'total_paid' => $existing ? (float) $existing->total_paid : 0.0,
            'remaining' => $existing ? (float) $existing->remaining : round($gross - $advancesToApply, 2),
            'status' => $existing?->status ?? 'calculated',
            'period_id' => $existing?->id,
            'attendance' => $attendance,
            'advances' => $advances->values()->all(),
        ];
    }

    public function outstandingAdvances(EmployeeDetail $employee, Carbon $through)
    {
        return EmployeeOrder::query()
            ->where('employee_id', $employee->id)
            ->where('type', 'loan')
            ->whereIn('status', ['approved', 'paid'])
            ->where('created_at', '<=', $through->copy()->endOfDay())
            ->withSum('salaryApplications as applied_amount', 'amount')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(function (EmployeeOrder $order) {
                $value = round((float) ($order->loan_value ?? 0), 2);
                $applied = round((float) ($order->applied_amount ?? 0), 2);

                return [
                    'employee_order_id' => (int) $order->id,
                    'date' => optional($order->created_at)->toDateString(),
                    'original_amount' => $value,
                    'applied_amount' => $applied,
                    'remaining_amount' => max(0, round($value - $applied, 2)),
                ];
            })
            ->filter(fn (array $advance) => $advance['remaining_amount'] > 0);
    }
}
