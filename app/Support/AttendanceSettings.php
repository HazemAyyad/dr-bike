<?php

namespace App\Support;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AttendanceSettings
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(): array
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
        $graceHour = self::afterMidnightGraceHour();

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
            'attendance_after_midnight_grace_hour' => $graceHour,
        ];
    }

    public static function afterMidnightGraceHour(): int
    {
        $hour = AppSetting::getInt(AppSetting::KEY_ATTENDANCE_AFTER_MIDNIGHT_GRACE_HOUR, 4);

        return max(1, min(6, $hour));
    }

    public static function reverseCheckoutWindowMinutes(): int
    {
        $window = AppSetting::getInt(AppSetting::KEY_FINGERPRINT_REVERSE_CHECKOUT_WINDOW_MINUTES, 60);

        return max(0, min(180, $window));
    }

    /** Cron time for auto-checkout: grace hour + 10 minutes. */
    public static function autoCheckoutCronTime(): string
    {
        return sprintf('%02d:10', self::afterMidnightGraceHour());
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public static function updateFromArray(array $data): array
    {
        $validated = Validator::make($data, [
            'attendance_qr_enabled' => ['required', 'boolean'],
            'attendance_fingerprint_enabled' => ['required', 'boolean'],
            'fingerprint_sync_mode' => ['required', Rule::in(['disabled', 'pull', 'push'])],
            'fingerprint_default_device_id' => ['nullable', 'integer', 'min:1'],
            'fingerprint_sync_interval_minutes' => ['required', 'integer', Rule::in([1, 5, 10, 15])],
            'fingerprint_auto_create_unknown_users' => ['required', 'boolean'],
            'fingerprint_deduplicate_minutes' => ['required', 'integer', 'min:0', 'max:60'],
            'fingerprint_push_token' => ['nullable', 'string', 'max:100'],
            'fingerprint_reverse_checkout_window_minutes' => ['required', 'integer', 'min:0', 'max:180'],
            'attendance_after_midnight_grace_hour' => ['required', 'integer', 'min:1', 'max:6'],
        ])->validate();

        AppSetting::set(AppSetting::KEY_ATTENDANCE_QR_ENABLED, $validated['attendance_qr_enabled'] ? '1' : '0');
        AppSetting::set(AppSetting::KEY_ATTENDANCE_FINGERPRINT_ENABLED, $validated['attendance_fingerprint_enabled'] ? '1' : '0');
        AppSetting::set(AppSetting::KEY_FINGERPRINT_SYNC_MODE, (string) $validated['fingerprint_sync_mode']);
        AppSetting::set(
            AppSetting::KEY_FINGERPRINT_DEFAULT_DEVICE_ID,
            $validated['fingerprint_default_device_id'] === null ? '' : (string) ((int) $validated['fingerprint_default_device_id'])
        );
        AppSetting::set(AppSetting::KEY_FINGERPRINT_SYNC_INTERVAL_MINUTES, (string) ((int) $validated['fingerprint_sync_interval_minutes']));
        AppSetting::set(AppSetting::KEY_FINGERPRINT_AUTO_CREATE_UNKNOWN_USERS, $validated['fingerprint_auto_create_unknown_users'] ? '1' : '0');
        AppSetting::set(AppSetting::KEY_FINGERPRINT_DEDUPLICATE_MINUTES, (string) ((int) $validated['fingerprint_deduplicate_minutes']));
        AppSetting::set(AppSetting::KEY_FINGERPRINT_PUSH_TOKEN, (string) ($validated['fingerprint_push_token'] ?? ''));
        AppSetting::set(
            AppSetting::KEY_FINGERPRINT_REVERSE_CHECKOUT_WINDOW_MINUTES,
            (string) ((int) $validated['fingerprint_reverse_checkout_window_minutes'])
        );
        AppSetting::set(
            AppSetting::KEY_ATTENDANCE_AFTER_MIDNIGHT_GRACE_HOUR,
            (string) ((int) $validated['attendance_after_midnight_grace_hour'])
        );

        return self::toArray();
    }
}
