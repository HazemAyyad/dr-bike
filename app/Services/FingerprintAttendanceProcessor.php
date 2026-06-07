<?php

namespace App\Services;

use App\Services\AdminNotificationService;
use App\Models\AppSetting;
use App\Models\AttendanceDevice;
use App\Models\EmployeeAttendance;
use App\Models\EmployeeAttendanceScan;
use App\Models\EmployeeDetail;
use App\Models\EmployeeDeviceMapping;
use App\Models\FingerprintDeviceUser;
use App\Models\FingerprintRawLog;
use App\Support\FingerprintAttendanceLogFilter;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class FingerprintAttendanceProcessor
{
    public function __construct(
        protected AttendanceSalaryService $salaryService
    ) {}

    public function processRawLog(FingerprintRawLog $rawLog): void
    {
        $rawLog->refresh();
        if ($rawLog->processing_status !== 'pending') {
            return;
        }

        if (! FingerprintAttendanceLogFilter::isAttendanceLog($rawLog)) {
            $this->mark($rawLog, 'ignored', 'operlog_not_attendance');

            return;
        }

        try {
            $device = $rawLog->attendanceDevice
                ?? AttendanceDevice::query()->find($rawLog->attendance_device_id);
            if (! $device) {
                $this->mark($rawLog, 'error', 'device_not_found');

                return;
            }

            $deviceUserId = (string) $rawLog->device_user_id;
            $this->ensureDeviceUserExists($device, $deviceUserId);

            $employee = $this->resolveEmployee($device, $deviceUserId);
            if (! $employee) {
                $this->mark($rawLog, 'ignored', 'employee_not_mapped');

                return;
            }

            if (! (bool) ($employee->fingerprint_enabled ?? true)) {
                $this->mark($rawLog, 'ignored', 'fingerprint_disabled');

                return;
            }

            $scanAt = Carbon::parse($rawLog->scan_time);
            $workDate = $scanAt->toDateString();

            if ($this->isWeeklyOff($employee, $scanAt)) {
                $this->mark($rawLog, 'ignored', 'weekly_off');

                return;
            }

            if ($this->isDuplicateScan($employee, $scanAt)) {
                $this->mark($rawLog, 'ignored', 'deduplicated');

                return;
            }

            $this->applyScan($employee, $device, $rawLog, $scanAt, $workDate);
            $this->mark($rawLog, 'processed', null);
        } catch (\Throwable $e) {
            Log::error('fingerprint.processor_failed', [
                'raw_log_id' => $rawLog->id,
                'message' => $e->getMessage(),
            ]);
            $this->mark($rawLog, 'error', $e->getMessage());
        }
    }

    protected function ensureDeviceUserExists(AttendanceDevice $device, string $deviceUserId): void
    {
        if ($deviceUserId === '') {
            return;
        }

        FingerprintDeviceUser::query()->firstOrCreate(
            [
                'attendance_device_id' => $device->id,
                'device_user_id' => $deviceUserId,
            ],
            [
                'last_synced_at' => now(),
            ]
        );
    }

    protected function resolveEmployee(AttendanceDevice $device, string $deviceUserId): ?EmployeeDetail
    {
        if ($deviceUserId === '') {
            return null;
        }

        $mapping = EmployeeDeviceMapping::query()
            ->where('attendance_device_id', $device->id)
            ->where('device_user_id', $deviceUserId)
            ->where('enabled', true)
            ->first();
        if ($mapping) {
            return EmployeeDetail::query()->find($mapping->employee_id);
        }

        $fdu = FingerprintDeviceUser::query()
            ->where('attendance_device_id', $device->id)
            ->where('device_user_id', $deviceUserId)
            ->first();
        if ($fdu && $fdu->linked_employee_id) {
            return EmployeeDetail::query()->find($fdu->linked_employee_id);
        }

        return EmployeeDetail::query()
            ->where('device_user_id', $deviceUserId)
            ->first();
    }

    protected function isWeeklyOff(EmployeeDetail $employee, Carbon $scanAt): bool
    {
        $dayName = strtolower($scanAt->format('l'));
        $offs = $this->salaryService->effectiveWeeklyDaysOff($employee);

        return in_array($dayName, $offs, true);
    }

    protected function isDuplicateScan(EmployeeDetail $employee, Carbon $scanAt): bool
    {
        $dedup = AppSetting::getInt(AppSetting::KEY_FINGERPRINT_DEDUPLICATE_MINUTES, 2);
        $dedup = max(0, min(60, $dedup));
        if ($dedup <= 0) {
            return false;
        }

        $last = EmployeeAttendanceScan::query()
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', $scanAt->toDateString())
            ->orderByDesc('scanned_at')
            ->first();

        if (! $last) {
            return false;
        }

        return Carbon::parse($last->scanned_at)->diffInMinutes($scanAt) < $dedup;
    }

    protected function applyScan(
        EmployeeDetail $employee,
        AttendanceDevice $device,
        FingerprintRawLog $rawLog,
        Carbon $scanAt,
        string $workDate
    ): void {
        $employeeId = (int) $employee->id;

        $scans = EmployeeAttendanceScan::query()
            ->where('employee_id', $employeeId)
            ->whereDate('work_date', $workDate)
            ->orderBy('id')
            ->get();

        $nextIsIn = $scans->isEmpty() || $scans->last()->direction === 'out';

        $attendance = EmployeeAttendance::firstOrNew([
            'employee_id' => $employeeId,
            'date' => $workDate,
        ]);

        $attendance->source = 'fingerprint';
        $attendance->attendance_device_id = $device->id;
        $attendance->device_user_id = (string) $rawLog->device_user_id;
        $attendance->fingerprint_raw_log_id = $rawLog->id;

        if ($nextIsIn) {
            EmployeeAttendanceScan::create([
                'employee_id' => $employeeId,
                'work_date' => $workDate,
                'scanned_at' => $scanAt,
                'direction' => 'in',
                'source' => 'fingerprint',
                'server_received_at' => $rawLog->serverReceivedAt(),
                'fingerprint_raw_log_id' => $rawLog->id,
            ]);

            if (! $attendance->exists || $attendance->arrived_at === null) {
                $attendance->arrived_at = $scanAt->format('H:i:s');
            }
            $attendance->left_at = null;

            $dayScans = EmployeeAttendanceScan::query()
                ->where('employee_id', $employeeId)
                ->whereDate('work_date', $workDate)
                ->orderBy('id')
                ->get();

            $attendance->worked_minutes = EmployeeAttendanceScan::computeWorkedMinutes($dayScans);
            $daily = $this->salaryService->calculateDailyOvertime($employee, (int) $attendance->worked_minutes);
            $attendance->required_minutes = $daily['required_minutes'];
            $attendance->normal_minutes = $daily['normal_minutes'];
            $attendance->overtime_minutes = $daily['overtime_minutes'];
            $attendance->save();

            try {
                app(AdminNotificationService::class)->notifyEmployeeLogin(
                    $employee,
                    (int) $attendance->id,
                    'fingerprint'
                );
            } catch (\Throwable $e) {
                Log::error('fingerprint.notify_login_failed', ['message' => $e->getMessage()]);
            }

            return;
        }

        if ($scans->isEmpty() || $scans->last()->direction !== 'in') {
            return;
        }

        EmployeeAttendanceScan::create([
            'employee_id' => $employeeId,
            'work_date' => $workDate,
            'scanned_at' => $scanAt,
            'direction' => 'out',
            'source' => 'fingerprint',
            'server_received_at' => $rawLog->serverReceivedAt(),
            'fingerprint_raw_log_id' => $rawLog->id,
        ]);

        $allScans = EmployeeAttendanceScan::query()
            ->where('employee_id', $employeeId)
            ->whereDate('work_date', $workDate)
            ->orderBy('id')
            ->get();

        $totalWorked = EmployeeAttendanceScan::computeWorkedMinutes($allScans);
        $attendance->worked_minutes = $totalWorked;
        $attendance->left_at = $scanAt->format('H:i:s');
        $daily = $this->salaryService->calculateDailyOvertime($employee, (int) $totalWorked);
        $attendance->required_minutes = $daily['required_minutes'];
        $attendance->normal_minutes = $daily['normal_minutes'];
        $attendance->overtime_minutes = $daily['overtime_minutes'];
        $attendance->save();

        try {
            $notifier = app(AdminNotificationService::class);
            $notifier->notifyEmployeeLogout(
                $employee,
                (int) $attendance->id,
                $scanAt->toIso8601String(),
                'fingerprint'
            );
            $pending = \App\Support\EmployeePendingTasksForToday::forEmployee($employeeId);
            $notifier->notifyEmployeeLogoutWithPendingTasks(
                $employee,
                (int) $attendance->id,
                $pending,
                $scanAt->toIso8601String()
            );
        } catch (\Throwable $e) {
            Log::error('fingerprint.notify_logout_failed', ['message' => $e->getMessage()]);
        }
    }

    protected function mark(FingerprintRawLog $rawLog, string $status, ?string $error): void
    {
        $rawLog->processing_status = $status;
        $rawLog->processing_error = $error;
        $rawLog->processed_at = now();
        $rawLog->save();
    }
}
