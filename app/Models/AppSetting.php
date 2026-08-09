<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class AppSetting extends Model
{
    public const KEY_SUBTASK_BONUS_DEFAULT = 'employee_task_subtask_bonus_default';
    public const KEY_ADMIN_FAB_OPTIONS = 'admin_fab_options';
    public const KEY_EMPLOYEE_ALLOWED_WIFI_SSIDS = 'employee_allowed_wifi_ssids';

    public const KEY_PASSWORD_RESET_OTP_DELIVERY_METHOD = 'password_reset_otp_delivery_method';

    public const KEY_APP_UPDATE_ADMIN_ANDROID_ACTIVE = 'app_update_admin_android_active';
    public const KEY_APP_UPDATE_ADMIN_ANDROID_LATEST_VERSION = 'app_update_admin_android_latest_version';
    public const KEY_APP_UPDATE_ADMIN_ANDROID_LATEST_BUILD = 'app_update_admin_android_latest_build';
    public const KEY_APP_UPDATE_ADMIN_ANDROID_MINIMUM_BUILD = 'app_update_admin_android_minimum_build';
    public const KEY_APP_UPDATE_ADMIN_ANDROID_FORCE = 'app_update_admin_android_force';
    public const KEY_APP_UPDATE_ADMIN_ANDROID_URL = 'app_update_admin_android_url';
    public const KEY_APP_UPDATE_ADMIN_ANDROID_TITLE = 'app_update_admin_android_title';
    public const KEY_APP_UPDATE_ADMIN_ANDROID_MESSAGE = 'app_update_admin_android_message';

    public const KEY_APP_UPDATE_ADMIN_IOS_ACTIVE = 'app_update_admin_ios_active';
    public const KEY_APP_UPDATE_ADMIN_IOS_LATEST_VERSION = 'app_update_admin_ios_latest_version';
    public const KEY_APP_UPDATE_ADMIN_IOS_LATEST_BUILD = 'app_update_admin_ios_latest_build';
    public const KEY_APP_UPDATE_ADMIN_IOS_MINIMUM_BUILD = 'app_update_admin_ios_minimum_build';
    public const KEY_APP_UPDATE_ADMIN_IOS_FORCE = 'app_update_admin_ios_force';
    public const KEY_APP_UPDATE_ADMIN_IOS_URL = 'app_update_admin_ios_url';
    public const KEY_APP_UPDATE_ADMIN_IOS_TITLE = 'app_update_admin_ios_title';
    public const KEY_APP_UPDATE_ADMIN_IOS_MESSAGE = 'app_update_admin_ios_message';

    public const KEY_APP_UPDATE_ADMIN_WINDOWS_ACTIVE = 'app_update_admin_windows_active';
    public const KEY_APP_UPDATE_ADMIN_WINDOWS_LATEST_VERSION = 'app_update_admin_windows_latest_version';
    public const KEY_APP_UPDATE_ADMIN_WINDOWS_LATEST_BUILD = 'app_update_admin_windows_latest_build';
    public const KEY_APP_UPDATE_ADMIN_WINDOWS_MINIMUM_BUILD = 'app_update_admin_windows_minimum_build';
    public const KEY_APP_UPDATE_ADMIN_WINDOWS_FORCE = 'app_update_admin_windows_force';
    public const KEY_APP_UPDATE_ADMIN_WINDOWS_URL = 'app_update_admin_windows_url';
    public const KEY_APP_UPDATE_ADMIN_WINDOWS_TITLE = 'app_update_admin_windows_title';
    public const KEY_APP_UPDATE_ADMIN_WINDOWS_MESSAGE = 'app_update_admin_windows_message';

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
