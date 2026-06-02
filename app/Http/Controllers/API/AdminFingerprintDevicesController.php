<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\AttendanceDevice;
use App\Models\FingerprintDeviceUser;
use App\Models\FingerprintRawLog;
use App\Services\FingerprintSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AdminFingerprintDevicesController extends Controller
{
    public function syncUsers(int $id, FingerprintSyncService $service)
    {
        try {
            $device = AttendanceDevice::query()->findOrFail($id);
            $result = $service->syncDeviceUsers($device);

            return response()->json([
                'status' => $result['ok'] ? 'success' : 'error',
                'message' => $result['message'],
                'synced' => (int) ($result['synced'] ?? 0),
            ], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'Device not found.'], 200);
        } catch (\Throwable $e) {
            Log::error('fingerprint.sync_users_failed', ['message' => $e->getMessage()]);

            return response()->json(['status' => 'error', 'message' => __('messages.something_wrong')], 200);
        }
    }

    public function syncLogs(int $id, FingerprintSyncService $service)
    {
        try {
            $device = AttendanceDevice::query()->findOrFail($id);
            $result = $service->syncAttendanceLogs($device);

            return response()->json([
                'status' => $result['ok'] ? 'success' : 'error',
                'message' => $result['message'],
                'synced' => (int) ($result['synced'] ?? 0),
            ], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'Device not found.'], 200);
        } catch (\Throwable $e) {
            Log::error('fingerprint.sync_logs_failed', ['message' => $e->getMessage()]);

            return response()->json(['status' => 'error', 'message' => __('messages.something_wrong')], 200);
        }
    }

    public function users(int $id)
    {
        try {
            $device = AttendanceDevice::query()->findOrFail($id);
            $rows = FingerprintDeviceUser::query()
                ->where('attendance_device_id', $device->id)
                ->orderBy('device_user_id')
                ->get()
                ->map(function (FingerprintDeviceUser $u) {
                    $employee = $u->linkedEmployee;

                    return [
                        'device_user_id' => (string) $u->device_user_id,
                        'name' => $u->name,
                        'linked_employee_id' => $u->linked_employee_id ? (int) $u->linked_employee_id : null,
                        'linked_employee_name' => $employee?->user?->name,
                        'last_synced_at' => $u->last_synced_at?->toIso8601String(),
                        'status' => $u->linked_employee_id ? 'linked' : 'unlinked',
                    ];
                })
                ->values();

            return response()->json([
                'status' => 'success',
                'device' => ['id' => (int) $device->id, 'name' => (string) $device->name],
                'users' => $rows,
            ], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'Device not found.'], 200);
        } catch (\Throwable $e) {
            Log::error('fingerprint.device_users_failed', ['message' => $e->getMessage()]);

            return response()->json(['status' => 'error', 'message' => __('messages.something_wrong')], 200);
        }
    }

    public function logs(int $id, Request $request)
    {
        try {
            $device = AttendanceDevice::query()->findOrFail($id);
            $limit = (int) $request->input('limit', 200);
            $limit = max(10, min(1000, $limit));

            $rows = FingerprintRawLog::query()
                ->where('attendance_device_id', $device->id)
                ->orderByDesc('scan_time')
                ->limit($limit)
                ->get()
                ->map(function (FingerprintRawLog $l) {
                    return [
                        'id' => (int) $l->id,
                        'device_user_id' => (string) $l->device_user_id,
                        'scan_time' => $l->scan_time?->toIso8601String(),
                        'verify_type' => $l->verify_type,
                        'status' => $l->status,
                        'processing_status' => $l->processing_status,
                        'processed_at' => $l->processed_at?->toIso8601String(),
                    ];
                })
                ->values();

            return response()->json([
                'status' => 'success',
                'device' => ['id' => (int) $device->id, 'name' => (string) $device->name],
                'logs' => $rows,
            ], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'Device not found.'], 200);
        } catch (\Throwable $e) {
            Log::error('fingerprint.device_logs_failed', ['message' => $e->getMessage()]);

            return response()->json(['status' => 'error', 'message' => __('messages.something_wrong')], 200);
        }
    }
}

