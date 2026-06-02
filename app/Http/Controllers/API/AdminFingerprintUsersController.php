<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\AttendanceDevice;
use App\Models\EmployeeDetail;
use App\Models\EmployeeDeviceMapping;
use App\Models\FingerprintDeviceUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AdminFingerprintUsersController extends Controller
{
    public function index(Request $request)
    {
        try {
            $deviceId = $request->input('device_id');
            if ($deviceId === null || $deviceId === '' || ! is_numeric($deviceId)) {
                throw ValidationException::withMessages(['device_id' => 'device_id is required.']);
            }
            $device = AttendanceDevice::query()->findOrFail((int) $deviceId);

            $users = FingerprintDeviceUser::query()
                ->where('attendance_device_id', $device->id)
                ->with(['linkedEmployee.user:id,name'])
                ->orderBy('device_user_id')
                ->get();

            $rows = $users->map(function (FingerprintDeviceUser $u) {
                $emp = $u->linkedEmployee;

                return [
                    'device_user_id' => (string) $u->device_user_id,
                    'name' => $u->name,
                    'linked_employee_id' => $u->linked_employee_id ? (int) $u->linked_employee_id : null,
                    'linked_employee_name' => $emp?->user?->name,
                    'last_synced_at' => $u->last_synced_at?->toIso8601String(),
                    'status' => $u->linked_employee_id ? 'linked' : 'unlinked',
                ];
            })->values();

            return response()->json([
                'status' => 'success',
                'device' => ['id' => (int) $device->id, 'name' => (string) $device->name],
                'users' => $rows,
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'Device not found.'], 200);
        } catch (\Throwable $e) {
            Log::error('fingerprint.users_index_failed', ['message' => $e->getMessage()]);

            return response()->json(['status' => 'error', 'message' => __('messages.something_wrong')], 200);
        }
    }

    public function link(Request $request, string $deviceUserId)
    {
        try {
            $data = $request->validate([
                'employee_id' => ['required', 'integer', 'exists:employee_details,id'],
                'device_id' => ['required', 'integer', 'exists:attendance_devices,id'],
            ]);

            $deviceId = (int) $data['device_id'];
            $employeeId = (int) $data['employee_id'];

            $fdu = FingerprintDeviceUser::query()
                ->where('attendance_device_id', $deviceId)
                ->where('device_user_id', $deviceUserId)
                ->firstOrFail();

            $employee = EmployeeDetail::query()->with('user:id,name')->findOrFail($employeeId);

            // Ensure 1 mapping per employee per device (unique constraint).
            EmployeeDeviceMapping::query()->updateOrCreate(
                ['employee_id' => $employeeId, 'attendance_device_id' => $deviceId],
                ['device_user_id' => $deviceUserId, 'device_user_name' => $fdu->name, 'enabled' => true]
            );

            // Persist on employee (so processor can match quickly)
            $employee->device_user_id = (string) $deviceUserId;
            $employee->fingerprint_enabled = true;
            $employee->save();

            $fdu->linked_employee_id = $employeeId;
            $fdu->save();

            return response()->json([
                'status' => 'success',
                'message' => __('messages.operationCompletedSuccessfully'),
                'user' => [
                    'device_user_id' => (string) $fdu->device_user_id,
                    'name' => $fdu->name,
                    'linked_employee_id' => $employeeId,
                    'linked_employee_name' => $employee->user?->name,
                    'status' => 'linked',
                ],
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'Not found.'], 200);
        } catch (\Throwable $e) {
            Log::error('fingerprint.user_link_failed', ['message' => $e->getMessage()]);
            return response()->json(['status' => 'error', 'message' => __('messages.something_wrong')], 200);
        }
    }

    public function unlink(Request $request, string $deviceUserId)
    {
        try {
            $data = $request->validate([
                'device_id' => ['required', 'integer', 'exists:attendance_devices,id'],
            ]);
            $deviceId = (int) $data['device_id'];

            $fdu = FingerprintDeviceUser::query()
                ->where('attendance_device_id', $deviceId)
                ->where('device_user_id', $deviceUserId)
                ->firstOrFail();

            $employeeId = $fdu->linked_employee_id ? (int) $fdu->linked_employee_id : null;
            $fdu->linked_employee_id = null;
            $fdu->save();

            if ($employeeId !== null) {
                EmployeeDeviceMapping::query()
                    ->where('employee_id', $employeeId)
                    ->where('attendance_device_id', $deviceId)
                    ->delete();

                $employee = EmployeeDetail::query()->find($employeeId);
                if ($employee && (string) ($employee->device_user_id ?? '') === (string) $deviceUserId) {
                    $employee->device_user_id = null;
                    $employee->fingerprint_enabled = false;
                    $employee->save();
                }
            }

            return response()->json([
                'status' => 'success',
                'message' => __('messages.operationCompletedSuccessfully'),
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'Not found.'], 200);
        } catch (\Throwable $e) {
            Log::error('fingerprint.user_unlink_failed', ['message' => $e->getMessage()]);
            return response()->json(['status' => 'error', 'message' => __('messages.something_wrong')], 200);
        }
    }
}

