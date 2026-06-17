<?php

namespace App\Support;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ShiplySettings
{
    public const MODE_TEST = 'test';

    public const MODE_LIVE = 'live';

    /**
     * @return array<string, mixed>
     */
    public static function toArray(): array
    {
        $mode = self::mode();
        $enabled = AppSetting::getBool(AppSetting::KEY_SHIPLY_ENABLED, true);

        return [
            'shiply_enabled' => $enabled,
            'shiply_mode' => $mode,
            'shiply_is_test' => $mode === self::MODE_TEST,
            'shiply_base_url' => self::baseUrl($mode),
            'shiply_api_configured' => self::apiKey($mode) !== '',
        ];
    }

    public static function isEnabled(): bool
    {
        return AppSetting::getBool(AppSetting::KEY_SHIPLY_ENABLED, true);
    }

    public static function mode(): string
    {
        $mode = strtolower(trim((string) AppSetting::get(AppSetting::KEY_SHIPLY_MODE, self::MODE_TEST)));

        return in_array($mode, [self::MODE_TEST, self::MODE_LIVE], true)
            ? $mode
            : self::MODE_TEST;
    }

    public static function isTestMode(): bool
    {
        return self::mode() === self::MODE_TEST;
    }

    public static function baseUrl(?string $mode = null): string
    {
        $mode = $mode ?? self::mode();
        $region = strtolower((string) config('shiply.region', 'palestine'));

        return (string) (config("shiply.base_urls.{$region}.{$mode}")
            ?? config('shiply.base_urls.palestine.test'));
    }

    public static function apiKey(?string $mode = null): string
    {
        $mode = $mode ?? self::mode();

        return trim((string) (config("shiply.api_keys.{$mode}") ?? ''));
    }

    public static function webhookUrl(): string
    {
        return rtrim((string) config('app.url'), '/').(string) config('shiply.webhook_path');
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
            'shiply_enabled' => ['required', 'boolean'],
            'shiply_mode' => ['required', Rule::in([self::MODE_TEST, self::MODE_LIVE])],
        ])->validate();

        AppSetting::set(AppSetting::KEY_SHIPLY_ENABLED, $validated['shiply_enabled'] ? '1' : '0');
        AppSetting::set(AppSetting::KEY_SHIPLY_MODE, (string) $validated['shiply_mode']);

        return self::toArray();
    }
}
