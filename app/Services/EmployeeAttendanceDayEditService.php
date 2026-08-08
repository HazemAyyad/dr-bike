<?php

namespace App\Services;

use App\Models\EmployeeAttendance;
use App\Models\EmployeeAttendanceAdjustment;
use App\Models\EmployeeAttendanceOvertimeRequest;
use App\Models\EmployeeAttendanceScan;
use App\Models\EmployeeDetail;
use App\Support\EmployeePendingTasksForToday;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class EmployeeAttendanceDayEditService
{
    public function __construct(
        protected AttendanceSalaryService $salaryService,
        protected EmployeeAttendanceOvertimeService $overtimeService
    ) {}

    /**
     * Replace a work day's scans with admin-edited check-in/out and recalculate totals.
     *
     * @return array{attendance: EmployeeAttendance, calculated_overtime_minutes: int}
     */
    public function updateDayTimes(
        EmployeeDetail $employee,
        string $workDate,
        Carbon $checkInAt,
        ?Carbon $checkOutAt = null,
        ?int $editedBy = null
    ): array {
        $tz = EmployeePendingTasksForToday::TIMEZONE;

        if ($checkInAt->timezone($tz)->toDateString() !== $workDate) {
            throw new \InvalidArgumentException(__('messages.check_in_must_match_work_date'));
        }

        if ($checkOutAt !== null) {
            if ($checkOutAt->timezone($tz)->toDateString() !== $workDate) {
                throw new \InvalidArgumentException(__('messages.checkout_time_must_match_work_date'));
            }
            if ($checkOutAt->lt($checkInAt)) {
                throw new \InvalidArgumentException(__('messages.checkout_time_before_check_in'));
            }
        }

        return DB::transaction(function () use ($employee, $workDate, $checkInAt, $checkOutAt, $editedBy) {
            $employeeId = (int) $employee->id;
            $beforeAttendance = EmployeeAttendance::query()
                ->where('employee_id', $employeeId)
                ->whereDate('date', $workDate)
                ->first();
            $beforeScans = EmployeeAttendanceScan::query()
                ->where('employee_id', $employeeId)
                ->whereDate('work_date', $workDate)
                ->orderBy('id')
                ->get();
            $beforeValues = $this->snapshot($beforeAttendance, $beforeScans);

            EmployeeAttendanceScan::query()
                ->where('employee_id', $employeeId)
                ->whereDate('work_date', $workDate)
                ->delete();

            EmployeeAttendanceScan::create([
                'employee_id' => $employeeId,
                'work_date' => $workDate,
                'scanned_at' => $checkInAt,
                'direction' => 'in',
                'source' => 'admin_edit',
                'server_received_at' => now(),
            ]);

            if ($checkOutAt !== null) {
                EmployeeAttendanceScan::create([
                    'employee_id' => $employeeId,
                    'work_date' => $workDate,
                    'scanned_at' => $checkOutAt,
                    'direction' => 'out',
                    'source' => 'admin_edit',
                    'server_received_at' => now(),
                ]);
            }

            $allScans = EmployeeAttendanceScan::query()
                ->where('employee_id', $employeeId)
                ->whereDate('work_date', $workDate)
                ->orderBy('id')
                ->get();

            $totalWorked = EmployeeAttendanceScan::computeWorkedMinutes($allScans);
            $countedCheckOutAt = $this->salaryService->countedCheckoutAt($employee, $workDate, $checkOutAt);
            if ($checkOutAt !== null && $countedCheckOutAt !== null && ! $countedCheckOutAt->equalTo($checkOutAt)) {
                $totalWorked = max(0, $checkInAt->diffInMinutes($countedCheckOutAt));
            }

            $attendance = EmployeeAttendance::firstOrNew([
                'employee_id' => $employeeId,
                'date' => $workDate,
            ]);

            $attendance->source = 'manual';
            $attendance->arrived_at = $checkInAt->format('H:i:s');
            $attendance->left_at = $checkOutAt?->format('H:i:s');
            $attendance->worked_minutes = $totalWorked;
            $attendance->missing_checkout = $checkOutAt === null;

            $daily = $this->salaryService->calculateDailyOvertimeForDate($employee, (int) $totalWorked, $workDate);
            $attendance->required_minutes = $daily['required_minutes'];
            $attendance->normal_minutes = $daily['normal_minutes'];
            $calculatedOvertime = (int) ($daily['overtime_minutes'] ?? 0);
            $attendance->overtime_minutes = $calculatedOvertime;
            $attendance->save();

            if ($checkOutAt !== null && $calculatedOvertime > 0) {
                $attendance = $this->overtimeService->applyCheckoutOvertimePolicy(
                    $attendance,
                    $employee,
                    'admin_edit',
                    $calculatedOvertime
                );
            } elseif ($checkOutAt !== null) {
                EmployeeAttendanceOvertimeRequest::query()
                    ->where('employee_attendance_id', $attendance->id)
                    ->where('status', EmployeeAttendanceOvertimeRequest::STATUS_PENDING)
                    ->update(['status' => EmployeeAttendanceOvertimeRequest::STATUS_REJECTED]);
            }

            $afterValues = $this->snapshot($attendance->fresh(), $allScans);

            $adjustment = EmployeeAttendanceAdjustment::create([
                'employee_attendance_id' => $attendance->id,
                'employee_id' => $employeeId,
                'work_date' => $workDate,
                'before_values' => $beforeValues,
                'after_values' => $afterValues,
                'edited_by' => $editedBy,
                'source' => 'admin_edit',
            ]);

            app(EmployeeActivityLogger::class)->log(
                $employeeId,
                $editedBy ? \App\Models\User::query()->find($editedBy) : null,
                'attendance',
                'attendance_day_updated',
                'تعديل دوام يوم',
                'تم تعديل دوام يوم '.$workDate,
                $attendance->fresh(),
                null,
                [
                    'work_date' => $workDate,
                    'adjustment_id' => (int) $adjustment->id,
                    'before_values' => $beforeValues,
                    'after_values' => $afterValues,
                ]
            );

            return [
                'attendance' => $attendance->fresh(),
                'calculated_overtime_minutes' => $calculatedOvertime,
            ];
        });
    }

    private function snapshot(?EmployeeAttendance $attendance, $scans): array
    {
        return [
            'attendance_id' => $attendance?->id,
            'arrived_at' => $attendance?->arrived_at,
            'left_at' => $attendance?->left_at,
            'worked_minutes' => (int) ($attendance?->worked_minutes ?? 0),
            'required_minutes' => (int) ($attendance?->required_minutes ?? 0),
            'normal_minutes' => (int) ($attendance?->normal_minutes ?? 0),
            'overtime_minutes' => (int) ($attendance?->overtime_minutes ?? 0),
            'scans' => collect($scans)->map(fn ($scan) => [
                'scanned_at' => $scan->scanned_at?->toIso8601String(),
                'direction' => $scan->direction,
                'source' => $scan->source,
            ])->values()->all(),
        ];
    }
}
