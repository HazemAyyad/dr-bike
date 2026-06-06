<?php

namespace App\Services;

use App\Models\AttendanceDevice;
use App\Models\FingerprintDeviceUser;
use App\Models\FingerprintRawLog;
use App\Support\FingerprintAttendanceLogFilter;
use Illuminate\Support\Facades\Log;

class FingerprintSyncService
{
    public function __construct(
        protected ZktecoPullService $pullService
    ) {}

    /**
     * @return array{ok: bool, message: string, synced: int}
     */
    public function syncDeviceUsers(AttendanceDevice $device): array
    {
        if ($this->isPushModeDevice($device)) {
            return [
                'ok' => false,
                'message' => 'الجهاز على وضع Push/ADMS. مزامنة المستخدمين تتم من الجهاز للسيرفر وليس العكس.',
                'synced' => 0,
            ];
        }

        $result = $this->pullService->fetchUsers($device);
        if (! ($result['ok'] ?? false)) {
            return [
                'ok' => false,
                'message' => (string) ($result['message'] ?? 'Failed to fetch users.'),
                'synced' => 0,
            ];
        }

        $users = $result['users'] ?? [];
        $synced = 0;
        foreach ($users as $u) {
            if (! is_array($u)) {
                continue;
            }
            $deviceUserId = (string) ($u['device_user_id'] ?? $u['uid'] ?? $u['id'] ?? '');
            if ($deviceUserId === '') {
                continue;
            }
            FingerprintDeviceUser::query()->updateOrCreate(
                [
                    'attendance_device_id' => $device->id,
                    'device_user_id' => $deviceUserId,
                ],
                [
                    'name' => $u['name'] ?? null,
                    'privilege' => $u['privilege'] ?? null,
                    'card' => $u['card'] ?? null,
                    'password' => $u['password'] ?? null,
                    'enabled' => array_key_exists('enabled', $u) ? (bool) $u['enabled'] : null,
                    'raw_payload' => $u,
                    'last_synced_at' => now(),
                ]
            );
            $synced++;
        }

        $device->last_sync_at = now();
        $device->last_sync_status = 'users_ok';
        $device->last_sync_error = null;
        $device->save();

        return ['ok' => true, 'message' => 'Users synced.', 'synced' => $synced];
    }

    /**
     * @return array{ok: bool, message: string, synced: int}
     */
    public function syncAttendanceLogs(AttendanceDevice $device): array
    {
        if ($this->isPushModeDevice($device)) {
            return [
                'ok' => false,
                'message' => 'الجهاز على وضع Push/ADMS. مزامنة السجلات تتم من الجهاز للسيرفر وليس العكس.',
                'synced' => 0,
            ];
        }

        $result = $this->pullService->fetchAttendanceLogs($device);
        if (! ($result['ok'] ?? false)) {
            return [
                'ok' => false,
                'message' => (string) ($result['message'] ?? 'Failed to fetch logs.'),
                'synced' => 0,
            ];
        }

        $logs = $result['logs'] ?? [];
        $synced = 0;
        foreach ($logs as $l) {
            if (! is_array($l)) {
                continue;
            }
            $deviceUserId = trim((string) ($l['device_user_id'] ?? $l['uid'] ?? $l['id'] ?? $l['PIN'] ?? $l['pin'] ?? ''));
            $scanTime = $l['scan_time'] ?? $l['timestamp'] ?? $l['time'] ?? $l['Time'] ?? null;
            $verifyType = isset($l['verify_type']) ? (string) $l['verify_type'] : (isset($l['Verify']) ? (string) $l['Verify'] : null);
            $status = isset($l['status']) ? (string) $l['status'] : (isset($l['Status']) ? (string) $l['Status'] : null);

            if ($deviceUserId === '') {
                $deviceUserId = 'RAW';
            }

            try {
                $scanAt = $scanTime !== null
                    ? (is_string($scanTime) ? now()->parse($scanTime) : $scanTime)
                    : now();
            } catch (\Throwable $e) {
                $scanAt = now();
            }

            $isAttendance = FingerprintAttendanceLogFilter::isAttendanceRow($deviceUserId, $verifyType, $status, $l);

            $rawLog = FingerprintRawLog::query()->firstOrCreate(
                [
                    'attendance_device_id' => $device->id,
                    'device_user_id' => $deviceUserId,
                    'scan_time' => $scanAt,
                ],
                [
                    'device_log_uid' => $l['device_log_uid'] ?? null,
                    'verify_type' => $verifyType,
                    'status' => $status,
                    'raw_payload' => $l,
                    'processing_status' => $isAttendance ? 'pending' : 'ignored',
                    'processing_error' => $isAttendance ? null : 'not_processed_for_attendance',
                ]
            );

            if ($isAttendance && $rawLog->processing_status === 'pending') {
                app(FingerprintAttendanceProcessor::class)->processRawLog($rawLog);
            }
            $synced++;
        }

        $device->last_sync_at = now();
        $device->last_sync_status = 'logs_ok';
        $device->last_sync_error = null;
        $device->save();

        return ['ok' => true, 'message' => 'Logs synced.', 'synced' => $synced];
    }

    public function markSyncError(AttendanceDevice $device, string $status, string $error): void
    {
        try {
            $device->last_sync_at = now();
            $device->last_sync_status = $status;
            $device->last_sync_error = $error;
            $device->save();
        } catch (\Throwable $e) {
            Log::error('fingerprint.device_sync_status_update_failed', ['message' => $e->getMessage()]);
        }
    }

    protected function isPushModeDevice(AttendanceDevice $device): bool
    {
        return strtolower((string) ($device->sync_mode ?? '')) === 'push';
    }
}

