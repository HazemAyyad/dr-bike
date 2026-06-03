<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\AttendanceDevice;
use App\Models\FingerprintDeviceUser;
use App\Models\FingerprintRawLog;
use App\Services\FingerprintActivityLogService;
use App\Services\FingerprintSyncService;
use App\Support\FingerprintAttendanceLogFilter;
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
                ->filter(fn (FingerprintDeviceUser $u) => FingerprintAttendanceLogFilter::isValidDeviceUserPin(
                    (string) $u->device_user_id
                ))
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

    public function logs(int $id, Request $request, FingerprintActivityLogService $activityLog)
    {
        try {
            $device = AttendanceDevice::query()->findOrFail($id);
            $days = (int) $request->input('days', 60);
            $limit = (int) $request->input('limit', 800);

            $grouped = $activityLog->buildGroupedDays($days, (int) $device->id, $limit);

            $flatLogs = [];
            foreach ($grouped['days'] as $day) {
                foreach ($day['scans'] as $scan) {
                    $flatLogs[] = $scan;
                }
            }

            return response()->json([
                'status' => 'success',
                'device' => ['id' => (int) $device->id, 'name' => (string) $device->name],
                'days' => $grouped['days'],
                'logs' => $flatLogs,
                'total_scans' => $grouped['total_scans'],
                'range_from' => $grouped['range_from'],
                'range_to' => $grouped['range_to'],
            ], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'Device not found.'], 200);
        } catch (\Throwable $e) {
            Log::error('fingerprint.device_logs_failed', ['message' => $e->getMessage()]);

            return response()->json(['status' => 'error', 'message' => __('messages.something_wrong')], 200);
        }
    }

    public function activityLog(Request $request, FingerprintActivityLogService $activityLog)
    {
        try {
            $days = (int) $request->input('days', 60);
            $limit = (int) $request->input('limit', 800);
            $deviceId = $request->filled('device_id') ? (int) $request->input('device_id') : null;

            if ($deviceId !== null) {
                AttendanceDevice::query()->findOrFail($deviceId);
            }

            $grouped = $activityLog->buildGroupedDays($days, $deviceId, $limit);

            return response()->json(array_merge([
                'status' => 'success',
            ], $grouped), 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'Device not found.'], 200);
        } catch (\Throwable $e) {
            Log::error('fingerprint.activity_log_failed', ['message' => $e->getMessage()]);

            return response()->json(['status' => 'error', 'message' => __('messages.something_wrong')], 200);
        }
    }
}

