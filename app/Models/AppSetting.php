<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class AppSetting extends Model
{
    public const KEY_SUBTASK_BONUS_DEFAULT = 'employee_task_subtask_bonus_default';
    public const KEY_ADMIN_FAB_OPTIONS = 'admin_fab_options';

    /** JSON array of preset size labels for product add/edit dropdown. */
    public const KEY_PRODUCT_SIZE_OPTIONS = 'product_size_options';

  /** Sales daily drawer: alert when |physical − system| ≥ this amount. */
    public const KEY_SALES_DAILY_VARIANCE_ALERT_THRESHOLD = 'sales_daily_variance_alert_threshold';

    /** JSON map currency => max float allowed at day close. */
    public const KEY_SALES_DAILY_MAX_FLOAT_JSON = 'sales_daily_max_float_json';

    // Attendance settings
    public const KEY_ATTENDANCE_QR_ENABLED = 'attendance_qr_enabled';
    public const KEY_ATTENDANCE_FINGERPRINT_ENABLED = 'attendance_fingerprint_enabled';
    public const KEY_FINGERPRINT_SYNC_MODE = 'fingerprint_sync_mode'; // disabled|pull|push
    public const KEY_FINGERPRINT_DEFAULT_DEVICE_ID = 'fingerprint_default_device_id';
    public const KEY_FINGERPRINT_SYNC_INTERVAL_MINUTES = 'fingerprint_sync_interval_minutes';
    public const KEY_FINGERPRINT_AUTO_CREATE_UNKNOWN_USERS = 'fingerprint_auto_create_unknown_users';
    public const KEY_FINGERPRINT_DEDUPLICATE_MINUTES = 'fingerprint_deduplicate_minutes';
    public const KEY_FINGERPRINT_PUSH_TOKEN = 'fingerprint_push_token';
    public const KEY_FINGERPRINT_REVERSE_CHECKOUT_WINDOW_MINUTES = 'fingerprint_reverse_checkout_window_minutes';

    /** Check-outs between 00:00 and this hour (exclusive) may belong to previous work day. */
    public const KEY_ATTENDANCE_AFTER_MIDNIGHT_GRACE_HOUR = 'attendance_after_midnight_grace_hour';

    /** Shiply delivery integration: test|live */
    public const KEY_SHIPLY_MODE = 'shiply_mode';

    /** Master switch for Shiply API calls */
    public const KEY_SHIPLY_ENABLED = 'shiply_enabled';

    public const KEY_AUTO_SYNC_META_CATALOG = 'auto_sync_meta_catalog';
    public const KEY_SHOW_QUANTITY_IN_META_CATALOG = 'enable_show_quantity_in_catalog';
    public const KEY_META_CATALOG_CURRENCY = 'meta_catalog_currency';
    public const KEY_META_CATALOG_DEFAULT_BRAND = 'meta_catalog_default_brand';

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
