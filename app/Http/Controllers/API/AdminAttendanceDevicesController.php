<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\AttendanceDevice;
use App\Models\FingerprintDeviceUser;
use App\Models\FingerprintRawLog;
use App\Services\AttendanceDeviceService;
use App\Support\FingerprintAttendanceLogFilter;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class AdminAttendanceDevicesController extends Controller
{
    public function index(Request $request)
    {
        $minimal = $request->boolean('minimal');

        $devices = AttendanceDevice::query()
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get(['id', 'name', 'is_active', 'model', 'serial_number', 'ip_address', 'port', 'communication_password', 'sync_mode', 'last_seen_at', 'last_sync_at', 'last_sync_status', 'last_sync_error']);

        if ($minimal) {
            $rows = $devices->map(fn (AttendanceDevice $d) => [
                'id' => (int) $d->id,
                'name' => (string) ($d->name ?? ''),
                'is_active' => (bool) $d->is_active,
            ])->values();

            return response()->json([
                'status' => 'success',
                'devices' => $rows,
            ], 200);
        }

        $deviceIds = $devices->pluck('id');
        $usersCounts = FingerprintDeviceUser::query()
            ->whereIn('attendance_device_id', $deviceIds)
            ->selectRaw('attendance_device_id, COUNT(*) as aggregate')
            ->groupBy('attendance_device_id')
            ->pluck('aggregate', 'attendance_device_id');
        $linkedCounts = FingerprintDeviceUser::query()
            ->whereIn('attendance_device_id', $deviceIds)
            ->whereNotNull('linked_employee_id')
            ->selectRaw('attendance_device_id, COUNT(*) as aggregate')
            ->groupBy('attendance_device_id')
            ->pluck('aggregate', 'attendance_device_id');
        $logsCounts = FingerprintAttendanceLogFilter::apply(
            FingerprintRawLog::query()->whereIn('attendance_device_id', $deviceIds)
        )
            ->selectRaw('attendance_device_id, COUNT(*) as aggregate')
            ->groupBy('attendance_device_id')
            ->pluck('aggregate', 'attendance_device_id');

        $rows = $devices->map(fn (AttendanceDevice $d) => $this->deviceRow(
            $d,
            (int) ($usersCounts[$d->id] ?? 0),
            (int) ($linkedCounts[$d->id] ?? 0),
            (int) ($logsCounts[$d->id] ?? 0),
        ))->values();

        return response()->json([
            'status' => 'success',
            'devices' => $rows,
        ], 200);
    }

    public function store(Request $request)
    {
        try {
            $data = $this->validateDevice($request);
            $device = AttendanceDevice::create($data);

            return response()->json([
                'status' => 'success',
                'message' => __('messages.operationCompletedSuccessfully'),
                'device' => $this->deviceRow($device->fresh()),
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (\Throwable $e) {
            Log::error('attendance_devices.store_failed', ['message' => $e->getMessage()]);

            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function update(Request $request, int $id)
    {
        try {
            $device = AttendanceDevice::query()->findOrFail($id);
            $data = $this->validateDevice($request, true);
            $device->update($data);

            return response()->json([
                'status' => 'success',
                'message' => __('messages.operationCompletedSuccessfully'),
                'device' => $this->deviceRow($device->fresh()),
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Device not found.',
            ], 200);
        } catch (\Throwable $e) {
            Log::error('attendance_devices.update_failed', ['message' => $e->getMessage()]);

            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function destroy(int $id)
    {
        try {
            $device = AttendanceDevice::query()->findOrFail($id);
            $device->delete();

            return response()->json([
                'status' => 'success',
                'message' => __('messages.operationCompletedSuccessfully'),
            ], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Device not found.',
            ], 200);
        } catch (\Throwable $e) {
            Log::error('attendance_devices.destroy_failed', ['message' => $e->getMessage()]);

            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function testConnection(int $id, AttendanceDeviceService $service)
    {
        try {
            $device = AttendanceDevice::query()->findOrFail($id);
            if ($this->isPushModeDevice($device)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'هذا الجهاز على وضع Push/ADMS. اختبار الاتصال من السيرفر غير متاح (الجهاز يرسل للسيرفر). تأكد من إعداد Cloud Server على الجهاز.',
                ], 200);
            }
            $result = $service->testConnection($device);

            if ($result['ok']) {
                $device->last_seen_at = now();
                $device->save();
            }

            return response()->json([
                'status' => $result['ok'] ? 'success' : 'error',
                'message' => $result['message'],
                'latency_ms' => $result['latency_ms'],
            ], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Device not found.',
            ], 200);
        } catch (\Throwable $e) {
            Log::error('attendance_devices.test_failed', ['message' => $e->getMessage()]);

            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function deviceRow(
        AttendanceDevice $d,
        ?int $usersCount = null,
        ?int $linkedCount = null,
        ?int $logsCount = null,
    ): array {
        if ($usersCount === null) {
            $usersCount = FingerprintDeviceUser::query()
                ->where('attendance_device_id', $d->id)
                ->count();
        }
        if ($linkedCount === null) {
            $linkedCount = FingerprintDeviceUser::query()
                ->where('attendance_device_id', $d->id)
                ->whereNotNull('linked_employee_id')
                ->count();
        }
        if ($logsCount === null) {
            $logsCount = FingerprintAttendanceLogFilter::apply(
                FingerprintRawLog::query()->where('attendance_device_id', $d->id)
            )->count();
        }

        $online = false;
        if ($d->last_seen_at) {
            $online = Carbon::parse($d->last_seen_at)->gte(now()->subMinutes(5));
        }

        return [
            'id' => (int) $d->id,
            'name' => (string) ($d->name ?? ''),
            'model' => $d->model,
            'serial_number' => $d->serial_number,
            'ip_address' => $d->ip_address,
            'port' => (int) ($d->port ?? 4370),
            'communication_password' => $d->communication_password,
            'is_active' => (bool) $d->is_active,
            'sync_mode' => (string) ($d->sync_mode ?? 'disabled'),
            'last_seen_at' => $d->last_seen_at?->toIso8601String(),
            'last_sync_at' => $d->last_sync_at?->toIso8601String(),
            'last_sync_status' => $d->last_sync_status,
            'last_sync_error' => $d->last_sync_error,
            'is_online' => $online,
            'users_count' => $usersCount,
            'linked_users_count' => $linkedCount,
            'fingerprint_logs_count' => $logsCount,
            'fingerprint_count' => $usersCount,
            'face_count' => 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function validateDevice(Request $request, bool $isUpdate = false): array
    {
        $rules = [
            'name' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'ip_address' => ['nullable', 'string', 'max:255'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'communication_password' => ['nullable', 'string', 'max:255'],
            'is_active' => [$isUpdate ? 'sometimes' : 'required', 'boolean'],
            'sync_mode' => [$isUpdate ? 'sometimes' : 'required', Rule::in(['pull', 'push', 'disabled'])],
        ];

        $data = $request->validate($rules);
        if (! array_key_exists('port', $data) || $data['port'] === null) {
            $data['port'] = 4370;
        }

        return $data;
    }

    protected function isPushModeDevice(AttendanceDevice $device): bool
    {
        return strtolower((string) ($device->sync_mode ?? '')) === 'push';
    }
}

