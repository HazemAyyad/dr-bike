<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Support\SalesDailySettings;
use App\Support\ShiplySettings;
use Illuminate\Http\Request;

class SalesSettingsController extends Controller
{
    public function show()
    {
        return response()->json([
            'status' => 'success',
            'settings' => $this->settings(),
        ]);
    }

    public function update(Request $request)
    {
        $currencies = config('sales_daily.currencies', ['شيكل', 'دولار', 'دينار']);
        $rules = [
            'sales_daily_variance_alert_threshold' => 'sometimes|numeric|min:0|max:999999',
            'sales_daily_max_float' => 'sometimes|array',
            'shiply' => 'sometimes|array',
        ];
        foreach ($currencies as $currency) {
            $rules['sales_daily_max_float.'.$currency] = 'sometimes|numeric|min:0|max:999999';
        }
        $data = $request->validate($rules);

        if ($request->has('sales_daily_variance_alert_threshold')) {
            AppSetting::set(
                AppSetting::KEY_SALES_DAILY_VARIANCE_ALERT_THRESHOLD,
                (float) $data['sales_daily_variance_alert_threshold']
            );
        }
        if ($request->has('sales_daily_max_float')) {
            $merged = SalesDailySettings::maxFloatMap();
            foreach ($currencies as $currency) {
                if (array_key_exists($currency, $data['sales_daily_max_float'])) {
                    $merged[$currency] = max(0, (float) $data['sales_daily_max_float'][$currency]);
                }
            }
            AppSetting::set(
                AppSetting::KEY_SALES_DAILY_MAX_FLOAT_JSON,
                json_encode($merged, JSON_UNESCAPED_UNICODE)
            );
        }
        if ($request->has('shiply')) {
            ShiplySettings::updateFromArray($data['shiply']);
        }

        return response()->json([
            'status' => 'success',
            'message' => __('messages.settings_updated'),
            'settings' => $this->settings(),
        ]);
    }

    private function settings(): array
    {
        return [
            'sales_daily_variance_alert_threshold' => SalesDailySettings::varianceAlertThreshold(),
            'sales_daily_max_float' => SalesDailySettings::maxFloatMap(),
            'shiply' => ShiplySettings::toArray(),
        ];
    }
}
