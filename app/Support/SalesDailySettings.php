<?php

namespace App\Support;

use App\Models\AppSetting;

class SalesDailySettings
{
    public static function varianceAlertThreshold(): float
    {
        $stored = AppSetting::get(AppSetting::KEY_SALES_DAILY_VARIANCE_ALERT_THRESHOLD);
        if ($stored !== null && $stored !== '') {
            return max(0, (float) $stored);
        }

        return max(0, (float) config('sales_daily.variance_alert_threshold', 50));
    }

    /**
     * @return array<string, float>
     */
    public static function maxFloatMap(): array
    {
        $defaults = config('sales_daily.max_float', []);
        if (! is_array($defaults)) {
            $defaults = [];
        }

        $raw = AppSetting::get(AppSetting::KEY_SALES_DAILY_MAX_FLOAT_JSON);
        if ($raw === null || $raw === '') {
            return self::normalizeFloatMap($defaults);
        }

        $decoded = json_decode((string) $raw, true);
        if (! is_array($decoded)) {
            return self::normalizeFloatMap($defaults);
        }

        return self::normalizeFloatMap(array_merge($defaults, $decoded));
    }

    public static function maxFloatForCurrency(string $currency): float
    {
        $map = self::maxFloatMap();

        return (float) ($map[$currency] ?? 500);
    }

    /**
     * @param  array<string, mixed>  $map
     * @return array<string, float>
     */
    private static function normalizeFloatMap(array $map): array
    {
        $normalized = [];
        foreach (config('sales_daily.currencies', []) as $currency) {
            $normalized[$currency] = max(0, (float) ($map[$currency] ?? 0));
        }

        return $normalized;
    }
}
