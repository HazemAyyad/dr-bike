<?php

namespace App\Services;

use App\Models\EmployeeAttendance;
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
        ?Carbon $checkOutAt = null
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

        return DB::transaction(function () use ($employee, $workDate, $checkInAt, $checkOutAt) {
            $employeeId = (int) $employee->id;

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

            $attendance = EmployeeAttendance::firstOrNew([
                'employee_id' => $employeeId,
                'date' => $workDate,
            ]);

            $attendance->source = 'manual';
            $attendance->arrived_at = $checkInAt->format('H:i:s');
            $attendance->left_at = $checkOutAt?->format('H:i:s');
            $attendance->worked_minutes = $totalWorked;
            $attendance->missing_checkout = $checkOutAt === null;

            $daily = $this->salaryService->calculateDailyOvertime($employee, (int) $totalWorked);
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

            return [
                'attendance' => $attendance->fresh(),
                'calculated_overtime_minutes' => $calculatedOvertime,
            ];
        });
    }
}
