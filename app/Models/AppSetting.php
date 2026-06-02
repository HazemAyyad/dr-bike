<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class AppSetting extends Model
{
    public const KEY_SUBTASK_BONUS_DEFAULT = 'employee_task_subtask_bonus_default';
    public const KEY_ADMIN_FAB_OPTIONS = 'admin_fab_options';

    // Attendance settings
    public const KEY_ATTENDANCE_QR_ENABLED = 'attendance_qr_enabled';
    public const KEY_ATTENDANCE_FINGERPRINT_ENABLED = 'attendance_fingerprint_enabled';
    public const KEY_FINGERPRINT_SYNC_MODE = 'fingerprint_sync_mode'; // disabled|pull|push
    public const KEY_FINGERPRINT_DEFAULT_DEVICE_ID = 'fingerprint_default_device_id';
    public const KEY_FINGERPRINT_SYNC_INTERVAL_MINUTES = 'fingerprint_sync_interval_minutes';
    public const KEY_FINGERPRINT_AUTO_CREATE_UNKNOWN_USERS = 'fingerprint_auto_create_unknown_users';
    public const KEY_FINGERPRINT_DEDUPLICATE_MINUTES = 'fingerprint_deduplicate_minutes';
    public const KEY_FINGERPRINT_PUSH_TOKEN = 'fingerprint_push_token';

    protected $fillable = [
        'key',
        'value',
    ];

    public static function get(string $key, mixed $default = null): mixed
    {
        return Cache::remember("app_setting:{$key}", 300, function () use ($key, $default) {
            $row = static::query()->where('key', $key)->first();

            return $row?->value ?? $default;
        });
    }

    public static function getInt(string $key, int $default = 0): int
    {
        return (int) static::get($key, (string) $default);
    }

    public static function getBool(string $key, bool $default = false): bool
    {
        $v = static::get($key, $default ? '1' : '0');
        if (is_bool($v)) {
            return $v;
        }
        $s = strtolower(trim((string) $v));

        return in_array($s, ['1', 'true', 'yes', 'on'], true);
    }

    public static function set(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(
            ['key' => $key],
            ['value' => (string) $value]
        );
        Cache::forget("app_setting:{$key}");
    }
}
