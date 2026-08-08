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
use App\Support\AttendanceWorkDateResolver;
use App\Services\EmployeeAttendanceOvertimeService;
use App\Support\FingerprintAttendanceLogFilter;
use Carbon\Carbon;
use Illuminate\Support\Collection;
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
            $workDate = AttendanceWorkDateResolver::defaultWorkDate($scanAt);

            if ($this->isDuplicateScan($employee, $scanAt)) {
                $this->mark($rawLog, 'ignored', 'deduplicated');

                return;
            }

            $skipReason = $this->applyScan($employee, $device, $rawLog, $scanAt, $workDate);
            if ($skipReason !== null) {
                $this->mark($rawLog, 'ignored', $skipReason);

                return;
            }

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

    public function employeeForRawLog(FingerprintRawLog $rawLog): ?EmployeeDetail
    {
        $device = $rawLog->attendanceDevice
            ?? AttendanceDevice::query()->find($rawLog->attendance_device_id);

        if (! $device) {
            return null;
        }

        return $this->resolveEmployee($device, (string) $rawLog->device_user_id);
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

    /**
     * @return string|null Skip/ignore reason, or null on success.
     */
    protected function applyScan(
        EmployeeDetail $employee,
        AttendanceDevice $device,
        FingerprintRawLog $rawLog,
        Carbon $scanAt,
        string $workDate
    ): ?string {
        $employeeId = (int) $employee->id;

        // After midnight and before 05:00, a scan may still be the check-out for yesterday.
        if (AttendanceWorkDateResolver::isBeforeCheckoutCutoff($scanAt)) {
            $workDate = AttendanceWorkDateResolver::workDateForPossibleCheckout($employeeId, $scanAt);
        }

        $scans = EmployeeAttendanceScan::query()
            ->where('employee_id', $employeeId)
            ->whereDate('work_date', $workDate)
            ->orderBy('id')
            ->get();

        $direction = $this->resolveScanDirection($rawLog, $scans);
        if ($direction === null) {
            return 'invalid_direction';
        }

        $isReverseCheckout = false;

        if (
            $direction === 'in'
            && $workDate !== AttendanceWorkDateResolver::defaultWorkDate($scanAt)
            && $scans->isNotEmpty()
            && $scans->last()?->direction === 'in'
        ) {
            $direction = 'out';
            $isReverseCheckout = true;
        }

        // Reverse checkout: if the device/user records an "IN" scan near the scheduled end time while still inside,
        // treat it as an "OUT" scan (common mistake on some devices).
        if (
            $direction === 'in'
            && $scans->isNotEmpty()
            && $scans->last()?->direction === 'in'
            && $this->shouldConvertInToReverseOut($employee, $scanAt, $workDate)
        ) {
            $direction = 'out';
            $isReverseCheckout = true;
        }

        $rejectReason = $this->rejectInvalidDirection($direction, $scans);
        if ($rejectReason !== null) {
            return $rejectReason;
        }

        $attendance = EmployeeAttendance::firstOrNew([
            'employee_id' => $employeeId,
            'date' => $workDate,
        ]);

        $attendance->source = 'fingerprint';
        $attendance->attendance_device_id = $device->id;
        $attendance->device_user_id = (string) $rawLog->device_user_id;
        $attendance->fingerprint_raw_log_id = $rawLog->id;
        // Real fingerprint scans should clear the "missing checkout" mark.
        $attendance->missing_checkout = false;

        $scan = EmployeeAttendanceScan::create([
            'employee_id' => $employeeId,
            'work_date' => $workDate,
            'scanned_at' => $scanAt,
            'direction' => $direction,
            'is_reverse_checkout' => $isReverseCheckout,
            'source' => 'fingerprint',
            'server_received_at' => $rawLog->serverReceivedAt(),
            'fingerprint_raw_log_id' => $rawLog->id,
        ]);

        if ($direction === 'in') {
            if (! $attendance->exists || $attendance->arrived_at === null) {
                $attendance->arrived_at = $scanAt->format('H:i:s');
            }
            $attendance->left_at = null;
        } else {
            $attendance->left_at = $scanAt->format('H:i:s');
            if ($attendance->arrived_at === null) {
                $firstIn = $scans->firstWhere('direction', 'in');
                if ($firstIn) {
                    $attendance->arrived_at = Carbon::parse($firstIn->scanned_at)->format('H:i:s');
                }
            }
        }

        $dayScans = EmployeeAttendanceScan::query()
            ->where('employee_id', $employeeId)
            ->whereDate('work_date', $workDate)
            ->orderBy('id')
            ->get();

        $workedMinutes = EmployeeAttendanceScan::computeWorkedMinutes($dayScans);
        $attendance->worked_minutes = $workedMinutes;
        $daily = $this->salaryService->calculateDailyOvertime($employee, (int) $workedMinutes);
        $attendance->required_minutes = $daily['required_minutes'];
        $attendance->normal_minutes = $daily['normal_minutes'];
        $attendance->overtime_minutes = $daily['overtime_minutes'];
        $attendance->save();

        if ($direction === 'out') {
            $calculatedOvertime = (int) ($daily['overtime_minutes'] ?? 0);
            if ($calculatedOvertime > 0) {
                $attendance = app(EmployeeAttendanceOvertimeService::class)->applyCheckoutOvertimePolicy(
                    $attendance,
                    $employee,
                    'fingerprint',
                    $calculatedOvertime
                );
            }
        }

        if ($direction === 'in') {
            $this->notifyLogin($employee, $attendance, $scanAt);
        } else {
            $this->notifyLogout($employee, $attendance, $scanAt, $employeeId, $isReverseCheckout);
        }

        app(EmployeeActivityLogger::class)->log(
            $employeeId,
            null,
            'attendance',
            $direction === 'in' ? 'attendance_check_in' : 'attendance_check_out',
            $direction === 'in' ? 'تسجيل دخول دوام' : 'تسجيل خروج دوام',
            $direction === 'in'
                ? 'سجل الموظف دخول دوام من البصمة'
                : 'سجل الموظف خروج دوام من البصمة',
            $attendance->fresh(),
            null,
            [
                'work_date' => $workDate,
                'scan_id' => (int) $scan->id,
                'source' => 'fingerprint',
                'device_id' => (int) $device->id,
                'device_user_id' => (string) $rawLog->device_user_id,
                'fingerprint_raw_log_id' => (int) $rawLog->id,
                'is_reverse_checkout' => $isReverseCheckout,
                'scanned_at' => $scanAt->toIso8601String(),
                'arrived_at' => $attendance->arrived_at,
                'left_at' => $attendance->left_at,
                'worked_minutes' => (int) ($attendance->worked_minutes ?? 0),
                'normal_minutes' => (int) ($attendance->normal_minutes ?? 0),
                'overtime_minutes' => (int) ($attendance->overtime_minutes ?? 0),
            ]
        );

        return null;
    }

    protected function shouldConvertInToReverseOut(EmployeeDetail $employee, Carbon $scanAt, string $workDate): bool
    {
        $window = AttendanceSettings::reverseCheckoutWindowMinutes();
        if ($window <= 0) {
            return false;
        }

        $end = trim((string) ($employee->end_work_time ?? ''));
        if ($end === '') {
            return false;
        }

        try {
            $scheduledEnd = Carbon::parse($workDate.' '.$end, \App\Support\EmployeePendingTasksForToday::TIMEZONE);
        } catch (\Throwable) {
            return false;
        }

        return abs($scheduledEnd->diffInMinutes($scanAt, false)) <= $window;
    }

    /**
     * Prefer ZKTeco device status (0=in, 1=out); fall back to alternating toggle when missing.
     *
     * @param  Collection<int, EmployeeAttendanceScan>  $scans
     */
    protected function resolveScanDirection(FingerprintRawLog $rawLog, Collection $scans): ?string
    {
        $fromDevice = FingerprintAttendanceLogFilter::directionFromDeviceStatus($rawLog->status);
        if ($fromDevice !== null) {
            return $fromDevice;
        }

        if ($scans->isEmpty() || $scans->last()->direction === 'out') {
            return 'in';
        }

        if ($scans->last()->direction === 'in') {
            return 'out';
        }

        return null;
    }

    /**
     * @param  Collection<int, EmployeeAttendanceScan>  $scans
     */
    protected function rejectInvalidDirection(string $direction, Collection $scans): ?string
    {
        $last = $scans->last();

        if ($direction === 'in' && $last && $last->direction === 'in') {
            return 'duplicate_checkin';
        }

        if ($direction === 'out' && $last && $last->direction === 'out') {
            return 'duplicate_checkout';
        }

        if ($direction === 'out' && ! $last) {
            return 'must_check_in_first';
        }

        return null;
    }

    protected function notifyLogin(EmployeeDetail $employee, EmployeeAttendance $attendance, Carbon $scanAt): void
    {
        try {
            app(AdminNotificationService::class)->notifyEmployeeLogin(
                $employee,
                (int) $attendance->id,
                'fingerprint',
                $scanAt->toIso8601String()
            );
        } catch (\Throwable $e) {
            Log::error('fingerprint.notify_login_failed', ['message' => $e->getMessage()]);
        }
    }

    protected function notifyLogout(
        EmployeeDetail $employee,
        EmployeeAttendance $attendance,
        Carbon $scanAt,
        int $employeeId,
        bool $isReverseCheckout = false
    ): void {
        try {
            $notifier = app(AdminNotificationService::class);
            $notifier->notifyEmployeeLogout(
                $employee,
                (int) $attendance->id,
                $scanAt->toIso8601String(),
                'fingerprint',
                $isReverseCheckout
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
