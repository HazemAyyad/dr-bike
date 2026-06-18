<?php

namespace App\Services;

use App\Models\EmployeeAttendance;
use App\Models\EmployeeAttendanceOvertimeRequest;
use App\Models\EmployeeDetail;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class EmployeeAttendanceOvertimeService
{
    public function __construct(
        protected AttendanceSalaryService $salaryService,
        protected AdminNotificationService $adminNotificationService
    ) {}

    /**
     * After checkout: hold contract overtime until admin approves.
     */
    public function applyCheckoutOvertimePolicy(
        EmployeeAttendance $attendance,
        EmployeeDetail $employee,
        string $source,
        int $calculatedOvertimeMinutes
    ): EmployeeAttendance {
        if ($source === 'auto' || $calculatedOvertimeMinutes <= 0) {
            return $attendance;
        }

        $attendance->overtime_minutes = 0;
        $attendance->save();

        $request = $this->createOrRefreshPending(
            $attendance,
            $employee,
            $calculatedOvertimeMinutes,
            $source
        );

        try {
            $this->adminNotificationService->notifyAttendanceOvertimePending($employee, $request);
        } catch (\Throwable $e) {
            Log::error('attendance.overtime_notify_failed', ['message' => $e->getMessage()]);
        }

        return $attendance->fresh();
    }

    public function createOrRefreshPending(
        EmployeeAttendance $attendance,
        EmployeeDetail $employee,
        int $requestedMinutes,
        string $source
    ): EmployeeAttendanceOvertimeRequest {
        $existing = EmployeeAttendanceOvertimeRequest::query()
            ->where('employee_attendance_id', $attendance->id)
            ->where('status', EmployeeAttendanceOvertimeRequest::STATUS_PENDING)
            ->first();

        if ($existing) {
            $existing->update([
                'requested_minutes' => max(0, $requestedMinutes),
                'checkout_source' => $source,
            ]);

            return $existing->fresh();
        }

        return EmployeeAttendanceOvertimeRequest::create([
            'employee_id' => $employee->id,
            'employee_attendance_id' => $attendance->id,
            'work_date' => $attendance->date,
            'requested_minutes' => max(0, $requestedMinutes),
            'status' => EmployeeAttendanceOvertimeRequest::STATUS_PENDING,
            'checkout_source' => $source,
        ]);
    }

    /**
     * @return Collection<int, EmployeeAttendanceOvertimeRequest>
     */
    public function listForAdmin(?string $status = 'pending'): Collection
    {
        $query = EmployeeAttendanceOvertimeRequest::query()
            ->with(['employee.user:id,name', 'attendance'])
            ->orderByDesc('id');

        if ($status !== null && $status !== '' && $status !== 'all') {
            $query->where('status', $status);
        }

        return $query->get();
    }

    public function approve(
        int $requestId,
        int $adminUserId,
        ?int $approvedMinutes = null,
        ?string $note = null
    ): EmployeeAttendanceOvertimeRequest {
        $request = EmployeeAttendanceOvertimeRequest::query()
            ->with(['employee', 'attendance'])
            ->findOrFail($requestId);

        if ($request->status !== EmployeeAttendanceOvertimeRequest::STATUS_PENDING) {
            throw new \InvalidArgumentException(__('messages.attendance_overtime_already_reviewed'));
        }

        $minutes = $approvedMinutes ?? (int) $request->requested_minutes;
        $minutes = max(0, $minutes);

        $attendance = $request->attendance;
        $employee = $request->employee;
        if (! $attendance || ! $employee) {
            throw new \InvalidArgumentException(__('messages.something_wrong'));
        }

        $worked = (int) ($attendance->worked_minutes ?? 0);
        $daily = $this->salaryService->calculateDailyOvertime($employee, $worked);
        $maxOvertime = max(0, $worked - (int) ($daily['required_minutes'] ?? 0));
        $approved = min($minutes, $maxOvertime);

        $attendance->required_minutes = (int) ($daily['required_minutes'] ?? 0);
        $attendance->overtime_minutes = $approved;
        $attendance->normal_minutes = max(0, $worked - $approved);
        $attendance->save();

        $request->update([
            'status' => EmployeeAttendanceOvertimeRequest::STATUS_APPROVED,
            'approved_minutes' => $approved,
            'reviewed_by' => $adminUserId,
            'reviewed_at' => now(),
            'admin_note' => $note,
        ]);

        return $request->fresh(['employee.user', 'attendance']);
    }

    public function reject(
        int $requestId,
        int $adminUserId,
        ?string $note = null
    ): EmployeeAttendanceOvertimeRequest {
        $request = EmployeeAttendanceOvertimeRequest::query()->findOrFail($requestId);

        if ($request->status !== EmployeeAttendanceOvertimeRequest::STATUS_PENDING) {
            throw new \InvalidArgumentException(__('messages.attendance_overtime_already_reviewed'));
        }

        $request->update([
            'status' => EmployeeAttendanceOvertimeRequest::STATUS_REJECTED,
            'approved_minutes' => 0,
            'reviewed_by' => $adminUserId,
            'reviewed_at' => now(),
            'admin_note' => $note,
        ]);

        return $request->fresh(['employee.user', 'attendance']);
    }

    public function findForAttendanceDay(int $attendanceId): ?EmployeeAttendanceOvertimeRequest
    {
        return EmployeeAttendanceOvertimeRequest::query()
            ->where('employee_attendance_id', $attendanceId)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(EmployeeAttendanceOvertimeRequest $request): array
    {
        $request->loadMissing(['employee.user', 'attendance']);

        return [
            'id' => (int) $request->id,
            'employee_id' => (int) $request->employee_id,
            'employee_name' => (string) ($request->employee?->user?->name ?? ''),
            'employee_attendance_id' => $request->employee_attendance_id !== null
                ? (int) $request->employee_attendance_id
                : null,
            'work_date' => $request->work_date?->toDateString(),
            'requested_minutes' => (int) $request->requested_minutes,
            'approved_minutes' => $request->approved_minutes !== null
                ? (int) $request->approved_minutes
                : null,
            'status' => (string) $request->status,
            'checkout_source' => (string) ($request->checkout_source ?? ''),
            'admin_note' => (string) ($request->admin_note ?? ''),
            'reviewed_at' => $request->reviewed_at?->toIso8601String(),
        ];
    }
}
