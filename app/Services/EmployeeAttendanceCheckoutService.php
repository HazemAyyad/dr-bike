<?php

namespace App\Services;

use App\Models\EmployeeAttendance;
use App\Models\EmployeeAttendanceScan;
use App\Models\EmployeeDetail;
use App\Support\EmployeeAttendanceToday;
use App\Support\EmployeePendingTasksForToday;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class EmployeeAttendanceCheckoutService
{
    public function __construct(
        protected AttendanceSalaryService $salaryService
    ) {}

    /**
     * Record a check-out scan for an employee (admin manual or scheduled job).
     *
     * @return array{attendance: EmployeeAttendance, segment_minutes: int, day_worked_minutes: int}
     */
    public function checkout(
        EmployeeDetail $employee,
        ?Carbon $checkoutAt = null,
        ?string $workDate = null,
        string $source = 'manual'
    ): array {
        $checkoutAt = $checkoutAt ?? now();
        $employeeId = (int) $employee->id;
        $workDate = $workDate ?? $checkoutAt->toDateString();

        if ($checkoutAt->toDateString() !== $workDate) {
            throw new \InvalidArgumentException(__('messages.checkout_time_must_match_work_date'));
        }

        $scans = EmployeeAttendanceScan::query()
            ->where('employee_id', $employeeId)
            ->whereDate('work_date', $workDate)
            ->orderBy('id')
            ->get();

        if ($scans->isEmpty() || $scans->last()->direction !== 'in') {
            throw new \InvalidArgumentException(__('messages.must_check_in_first'));
        }

        $lastIn = $scans->last();
        if ($checkoutAt->lt(Carbon::parse($lastIn->scanned_at))) {
            throw new \InvalidArgumentException(__('messages.checkout_time_before_check_in'));
        }

        $segmentMinutes = max(0, Carbon::parse($lastIn->scanned_at)->diffInMinutes($checkoutAt));

        EmployeeAttendanceScan::create([
            'employee_id' => $employeeId,
            'work_date' => $workDate,
            'scanned_at' => $checkoutAt,
            'direction' => 'out',
            'source' => match ($source) {
                'fingerprint' => 'fingerprint',
                'auto' => 'auto',
                default => 'manual',
            },
            'server_received_at' => now(),
        ]);

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

        if (! $attendance->exists || ! in_array((string) ($attendance->source ?? ''), ['fingerprint'], true)) {
            $attendance->source = $source;
        }

        $attendance->worked_minutes = $totalWorked;
        $attendance->left_at = $checkoutAt->format('H:i:s');
        if ($attendance->arrived_at === null && $allScans->firstWhere('direction', 'in')) {
            $firstIn = $allScans->firstWhere('direction', 'in');
            $attendance->arrived_at = Carbon::parse($firstIn->scanned_at)->format('H:i:s');
        }

        $daily = $this->salaryService->calculateDailyOvertime($employee, (int) $totalWorked);
        $attendance->required_minutes = $daily['required_minutes'];
        $attendance->normal_minutes = $daily['normal_minutes'];
        $attendance->overtime_minutes = $daily['overtime_minutes'];
        $attendance->save();

        if ($source !== 'auto') {
            $this->notifyCheckout($employee, $attendance, $checkoutAt, $source);
        }

        return [
            'attendance' => $attendance,
            'segment_minutes' => $segmentMinutes,
            'day_worked_minutes' => $totalWorked,
        ];
    }

    public function isCurrentlyIn(int $employeeId, ?string $workDate = null): bool
    {
        $workDate = $workDate ?? EmployeeAttendanceToday::todayDateString();

        $last = EmployeeAttendanceScan::query()
            ->where('employee_id', $employeeId)
            ->whereDate('work_date', $workDate)
            ->orderByDesc('id')
            ->first();

        if ($last) {
            return $last->direction === 'in';
        }

        $legacy = EmployeeAttendance::query()
            ->where('employee_id', $employeeId)
            ->whereDate('date', $workDate)
            ->first();

        return $legacy
            && $legacy->arrived_at
            && ($legacy->left_at === null || $legacy->left_at === '00:00:00' || trim((string) $legacy->left_at) === '');
    }

    protected function notifyCheckout(
        EmployeeDetail $employee,
        EmployeeAttendance $attendance,
        Carbon $checkoutAt,
        string $source
    ): void {
        try {
            $notifier = app(AdminNotificationService::class);
            $notifier->notifyEmployeeLogout(
                $employee,
                (int) $attendance->id,
                $checkoutAt->toIso8601String(),
                $source
            );
            $pending = EmployeePendingTasksForToday::forEmployee((int) $employee->id);
            $notifier->notifyEmployeeLogoutWithPendingTasks(
                $employee,
                (int) $attendance->id,
                $pending,
                $checkoutAt->toIso8601String()
            );
        } catch (\Throwable $e) {
            Log::error('attendance.manual_checkout_notify_failed', ['message' => $e->getMessage()]);
        }
    }
}
