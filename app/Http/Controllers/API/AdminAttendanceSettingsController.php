<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class AdminAttendanceSettingsController extends Controller
{
    public function show(Request $request)
    {
        try {
            $settings = $this->readAttendanceSettings();

            return response()->json([
                'status' => 'success',
                'settings' => $settings,
            ], 200);
        } catch (\Throwable $e) {
            Log::error('attendance_settings.show_failed', ['message' => $e->getMessage()]);

            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function update(Request $request)
    {
        try {
            $data = $request->validate([
                'attendance_qr_enabled' => ['required', 'boolean'],
                'attendance_fingerprint_enabled' => ['required', 'boolean'],
                'fingerprint_sync_mode' => ['required', Rule::in(['disabled', 'pull', 'push'])],
                'fingerprint_default_device_id' => ['nullable', 'integer', 'min:1'],
                'fingerprint_sync_interval_minutes' => ['required', 'integer', Rule::in([1, 5, 10, 15])],
                'fingerprint_auto_create_unknown_users' => ['required', 'boolean'],
                'fingerprint_deduplicate_minutes' => ['required', 'integer', 'min:0', 'max:60'],
                'fingerprint_push_token' => ['nullable', 'string', 'max:100'],
                // If an employee scans "IN" near scheduled end while still inside,
                // treat it as a reverse check-out (OUT) to correct common device/user mistakes.
                'fingerprint_reverse_checkout_window_minutes' => ['required', 'integer', 'min:0', 'max:180'],
            ]);

            AppSetting::set(AppSetting::KEY_ATTENDANCE_QR_ENABLED, $data['attendance_qr_enabled'] ? '1' : '0');
            AppSetting::set(AppSetting::KEY_ATTENDANCE_FINGERPRINT_ENABLED, $data['attendance_fingerprint_enabled'] ? '1' : '0');
            AppSetting::set(AppSetting::KEY_FINGERPRINT_SYNC_MODE, (string) $data['fingerprint_sync_mode']);
            AppSetting::set(
                AppSetting::KEY_FINGERPRINT_DEFAULT_DEVICE_ID,
                $data['fingerprint_default_device_id'] === null ? '' : (string) ((int) $data['fingerprint_default_device_id'])
            );
            AppSetting::set(AppSetting::KEY_FINGERPRINT_SYNC_INTERVAL_MINUTES, (string) ((int) $data['fingerprint_sync_interval_minutes']));
            AppSetting::set(AppSetting::KEY_FINGERPRINT_AUTO_CREATE_UNKNOWN_USERS, $data['fingerprint_auto_create_unknown_users'] ? '1' : '0');
            AppSetting::set(AppSetting::KEY_FINGERPRINT_DEDUPLICATE_MINUTES, (string) ((int) $data['fingerprint_deduplicate_minutes']));
            AppSetting::set(AppSetting::KEY_FINGERPRINT_PUSH_TOKEN, (string) ($data['fingerprint_push_token'] ?? ''));
            AppSetting::set(AppSetting::KEY_FINGERPRINT_REVERSE_CHECKOUT_WINDOW_MINUTES, (string) ((int) $data['fingerprint_reverse_checkout_window_minutes']));

            return response()->json([
                'status' => 'success',
                'message' => __('messages.settings_updated'),
                'settings' => $this->readAttendanceSettings(),
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (\Throwable $e) {
            Log::error('attendance_settings.update_failed', ['message' => $e->getMessage()]);

            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function readAttendanceSettings(): array
    {
        $qrEnabled = AppSetting::getBool(AppSetting::KEY_ATTENDANCE_QR_ENABLED, true);
        $fpEnabled = AppSetting::getBool(AppSetting::KEY_ATTENDANCE_FINGERPRINT_ENABLED, false);
        $syncMode = (string) AppSetting::get(AppSetting::KEY_FINGERPRINT_SYNC_MODE, 'disabled');
        if (! in_array($syncMode, ['disabled', 'pull', 'push'], true)) {
            $syncMode = 'disabled';
        }

        $defaultDeviceRaw = trim((string) AppSetting::get(AppSetting::KEY_FINGERPRINT_DEFAULT_DEVICE_ID, ''));
        $defaultDeviceId = $defaultDeviceRaw !== '' && is_numeric($defaultDeviceRaw) ? (int) $defaultDeviceRaw : null;

        $interval = AppSetting::getInt(AppSetting::KEY_FINGERPRINT_SYNC_INTERVAL_MINUTES, 5);
        if (! in_array($interval, [1, 5, 10, 15], true)) {
            $interval = 5;
        }

        $autoCreate = AppSetting::getBool(AppSetting::KEY_FINGERPRINT_AUTO_CREATE_UNKNOWN_USERS, false);
        $dedup = AppSetting::getInt(AppSetting::KEY_FINGERPRINT_DEDUPLICATE_MINUTES, 2);
        $dedup = max(0, min(60, $dedup));
        $reverseWindow = AppSetting::getInt(AppSetting::KEY_FINGERPRINT_REVERSE_CHECKOUT_WINDOW_MINUTES, 60);
        $reverseWindow = max(0, min(180, $reverseWindow));

        return [
            'attendance_qr_enabled' => $qrEnabled,
            'attendance_fingerprint_enabled' => $fpEnabled,
            'fingerprint_sync_mode' => $syncMode,
            'fingerprint_default_device_id' => $defaultDeviceId,
            'fingerprint_sync_interval_minutes' => $interval,
            'fingerprint_auto_create_unknown_users' => $autoCreate,
            'fingerprint_deduplicate_minutes' => $dedup,
            'fingerprint_push_token' => trim((string) AppSetting::get(AppSetting::KEY_FINGERPRINT_PUSH_TOKEN, '')),
            'fingerprint_reverse_checkout_window_minutes' => $reverseWindow,
        ];
    }
}

