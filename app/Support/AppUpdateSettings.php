<?php

namespace App\Support;

use App\Models\AppSetting;

class AppUpdateSettings
{
    public const STORE_PASSWORD_RESET_MINIMUM_BUILD = 10;

    private const APP_PLATFORMS = [
        'admin' => ['android', 'ios', 'windows'],
        'store' => ['android', 'ios'],
    ];

    private const KEY_MAP = [
        'admin' => [
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
        ],
        'store' => [
            'android' => [
                'is_active' => AppSetting::KEY_APP_UPDATE_STORE_ANDROID_ACTIVE,
                'latest_version' => AppSetting::KEY_APP_UPDATE_STORE_ANDROID_LATEST_VERSION,
                'latest_build' => AppSetting::KEY_APP_UPDATE_STORE_ANDROID_LATEST_BUILD,
                'minimum_build' => AppSetting::KEY_APP_UPDATE_STORE_ANDROID_MINIMUM_BUILD,
                'force_update' => AppSetting::KEY_APP_UPDATE_STORE_ANDROID_FORCE,
                'url' => AppSetting::KEY_APP_UPDATE_STORE_ANDROID_URL,
                'title' => AppSetting::KEY_APP_UPDATE_STORE_ANDROID_TITLE,
                'message' => AppSetting::KEY_APP_UPDATE_STORE_ANDROID_MESSAGE,
            ],
            'ios' => [
                'is_active' => AppSetting::KEY_APP_UPDATE_STORE_IOS_ACTIVE,
                'latest_version' => AppSetting::KEY_APP_UPDATE_STORE_IOS_LATEST_VERSION,
                'latest_build' => AppSetting::KEY_APP_UPDATE_STORE_IOS_LATEST_BUILD,
                'minimum_build' => AppSetting::KEY_APP_UPDATE_STORE_IOS_MINIMUM_BUILD,
                'force_update' => AppSetting::KEY_APP_UPDATE_STORE_IOS_FORCE,
                'url' => AppSetting::KEY_APP_UPDATE_STORE_IOS_URL,
                'title' => AppSetting::KEY_APP_UPDATE_STORE_IOS_TITLE,
                'message' => AppSetting::KEY_APP_UPDATE_STORE_IOS_MESSAGE,
            ],
        ],
    ];

    public static function all(): array
    {
        return collect(self::APP_PLATFORMS)->mapWithKeys(fn (array $platforms, string $app) => [
            $app => collect($platforms)->mapWithKeys(fn (string $platform) => [
                $platform => self::platform($platform, $app),
            ])->all(),
        ])->all();
    }

    public static function platform(string $platform, string $app = 'admin'): array
    {
        $app = strtolower($app);
        $platform = strtolower($platform);
        $keys = self::KEY_MAP[$app][$platform]
            ?? self::KEY_MAP[$app]['android']
            ?? self::KEY_MAP['admin']['android'];

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
        foreach (self::APP_PLATFORMS as $app => $platforms) {
            $incomingApp = $settings[$app] ?? ($app === 'admin' ? $settings : null);
            if (! is_array($incomingApp)) {
                continue;
            }

            foreach ($platforms as $platform) {
                $incoming = $incomingApp[$platform] ?? null;
                if (is_array($incoming)) {
                    self::updatePlatform($app, $platform, $incoming);
                }
            }
        }

        return self::all();
    }

    private static function updatePlatform(string $app, string $platform, array $incoming): void
    {
        $keys = self::KEY_MAP[$app][$platform];

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
