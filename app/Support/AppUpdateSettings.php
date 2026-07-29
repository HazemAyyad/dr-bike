<?php

namespace App\Support;

use App\Models\AppSetting;

class AppUpdateSettings
{
    private const PLATFORMS = ['android', 'ios', 'windows'];

    private const KEY_MAP = [
        'android' => [
            'is_active' => AppSetting::KEY_APP_UPDATE_ADMIN_ANDROID_ACTIVE,
            'latest_version' => AppSetting::KEY_APP_UPDATE_ADMIN_ANDROID_LATEST_VERSION,
            'latest_build' => AppSetting::KEY_APP_UPDATE_ADMIN_ANDROID_LATEST_BUILD,
            'minimum_build' => AppSetting::KEY_APP_UPDATE_ADMIN_ANDROID_MINIMUM_BUILD,
            'force_update' => AppSetting::KEY_APP_UPDATE_ADMIN_ANDROID_FORCE,
            'url' => AppSetting::KEY_APP_UPDATE_ADMIN_ANDROID_URL,
            'title' => AppSetting::KEY_APP_UPDATE_ADMIN_ANDROID_TITLE,
            'message' => AppSetting::KEY_APP_UPDATE_ADMIN_ANDROID_MESSAGE,
        ],
        'ios' => [
            'is_active' => AppSetting::KEY_APP_UPDATE_ADMIN_IOS_ACTIVE,
            'latest_version' => AppSetting::KEY_APP_UPDATE_ADMIN_IOS_LATEST_VERSION,
            'latest_build' => AppSetting::KEY_APP_UPDATE_ADMIN_IOS_LATEST_BUILD,
            'minimum_build' => AppSetting::KEY_APP_UPDATE_ADMIN_IOS_MINIMUM_BUILD,
            'force_update' => AppSetting::KEY_APP_UPDATE_ADMIN_IOS_FORCE,
            'url' => AppSetting::KEY_APP_UPDATE_ADMIN_IOS_URL,
            'title' => AppSetting::KEY_APP_UPDATE_ADMIN_IOS_TITLE,
            'message' => AppSetting::KEY_APP_UPDATE_ADMIN_IOS_MESSAGE,
        ],
        'windows' => [
            'is_active' => AppSetting::KEY_APP_UPDATE_ADMIN_WINDOWS_ACTIVE,
            'latest_version' => AppSetting::KEY_APP_UPDATE_ADMIN_WINDOWS_LATEST_VERSION,
            'latest_build' => AppSetting::KEY_APP_UPDATE_ADMIN_WINDOWS_LATEST_BUILD,
            'minimum_build' => AppSetting::KEY_APP_UPDATE_ADMIN_WINDOWS_MINIMUM_BUILD,
            'force_update' => AppSetting::KEY_APP_UPDATE_ADMIN_WINDOWS_FORCE,
            'url' => AppSetting::KEY_APP_UPDATE_ADMIN_WINDOWS_URL,
            'title' => AppSetting::KEY_APP_UPDATE_ADMIN_WINDOWS_TITLE,
            'message' => AppSetting::KEY_APP_UPDATE_ADMIN_WINDOWS_MESSAGE,
        ],
    ];

    public static function all(): array
    {
        return [
            'admin' => [
                'android' => self::platform('android'),
                'ios' => self::platform('ios'),
                'windows' => self::platform('windows'),
            ],
        ];
    }

    public static function platform(string $platform): array
    {
        $platform = strtolower($platform);
        $keys = self::KEY_MAP[$platform] ?? self::KEY_MAP['android'];

        return [
            'is_active' => AppSetting::getBool($keys['is_active'], false),
            'latest_version' => (string) AppSetting::get($keys['latest_version'], '1.0.0'),
            'latest_build' => AppSetting::getInt($keys['latest_build'], 0),
            'minimum_build' => AppSetting::getInt($keys['minimum_build'], 0),
            'force_update' => AppSetting::getBool($keys['force_update'], false),
            'url' => (string) AppSetting::get($keys['url'], ''),
            'title' => (string) AppSetting::get($keys['title'], 'تحديث جديد متاح'),
            'message' => (string) AppSetting::get($keys['message'], 'يرجى تحديث التطبيق للحصول على آخر التحسينات.'),
        ];
    }

    public static function updateFromArray(array $settings): array
    {
        $admin = $settings['admin'] ?? $settings;
        if (! is_array($admin)) {
            return self::all();
        }

        foreach (self::PLATFORMS as $platform) {
            $incoming = $admin[$platform] ?? null;
            if (! is_array($incoming)) {
                continue;
            }

            self::updatePlatform($platform, $incoming);
        }

        return self::all();
    }

    private static function updatePlatform(string $platform, array $incoming): void
    {
        $keys = self::KEY_MAP[$platform];

        foreach (['is_active', 'force_update'] as $field) {
            if (array_key_exists($field, $incoming)) {
                AppSetting::set($keys[$field], filter_var($incoming[$field], FILTER_VALIDATE_BOOL) ? '1' : '0');
            }
        }

        foreach (['latest_build', 'minimum_build'] as $field) {
            if (array_key_exists($field, $incoming)) {
                AppSetting::set($keys[$field], max(0, (int) $incoming[$field]));
            }
        }

        foreach (['latest_version', 'url', 'title', 'message'] as $field) {
            if (array_key_exists($field, $incoming)) {
                AppSetting::set($keys[$field], (string) ($incoming[$field] ?? ''));
            }
        }
    }
}
