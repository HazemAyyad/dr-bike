<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Support\SalesDailySettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AppSettingsController extends Controller
{
    public function show(Request $request)
    {
        try {
            return response()->json([
                'status' => 'success',
                'settings' => [
                    'employee_task_subtask_bonus_default' => AppSetting::getInt(
                        AppSetting::KEY_SUBTASK_BONUS_DEFAULT,
                        5
                    ),
                    'admin_fab_options' => AppSetting::get(
                        AppSetting::KEY_ADMIN_FAB_OPTIONS,
                        'newInvoice,newEmployee,newExpense,newCustomer'
                    ),
                    'sales_daily_variance_alert_threshold' => SalesDailySettings::varianceAlertThreshold(),
                    'sales_daily_max_float' => SalesDailySettings::maxFloatMap(),
                ],
            ], 200);
        } catch (\Throwable $e) {
            Log::error('app_settings.show_failed', ['message' => $e->getMessage()]);

            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function update(Request $request)
    {
        try {
            $admin = $request->user();
            if (! $admin || $admin->type !== 'admin') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unauthorized. Admins only.',
                ], 200);
            }

            $currencies = config('sales_daily.currencies', ['شيكل', 'دولار', 'دينار']);
            $maxFloatRules = [];
            foreach ($currencies as $currency) {
                $maxFloatRules['sales_daily_max_float.'.$currency] = 'sometimes|numeric|min:0|max:999999';
            }

            $data = $request->validate(array_merge([
                'employee_task_subtask_bonus_default' => 'sometimes|integer|min:0|max:9999',
                'admin_fab_options' => 'nullable|string|max:500',
                'sales_daily_variance_alert_threshold' => 'sometimes|numeric|min:0|max:999999',
                'sales_daily_max_float' => 'sometimes|array',
            ], $maxFloatRules));

            if ($request->has('employee_task_subtask_bonus_default')) {
                AppSetting::set(
                    AppSetting::KEY_SUBTASK_BONUS_DEFAULT,
                    (int) $data['employee_task_subtask_bonus_default']
                );
            }
            if ($request->has('admin_fab_options')) {
                AppSetting::set(
                    AppSetting::KEY_ADMIN_FAB_OPTIONS,
                    (string) ($data['admin_fab_options'] ?? '')
                );
            }
            if ($request->has('sales_daily_variance_alert_threshold')) {
                AppSetting::set(
                    AppSetting::KEY_SALES_DAILY_VARIANCE_ALERT_THRESHOLD,
                    (float) $data['sales_daily_variance_alert_threshold']
                );
            }
            if ($request->has('sales_daily_max_float')) {
                $incoming = $request->input('sales_daily_max_float', []);
                $merged = SalesDailySettings::maxFloatMap();
                if (is_array($incoming)) {
                    foreach ($currencies as $currency) {
                        if (array_key_exists($currency, $incoming)) {
                            $merged[$currency] = max(0, (float) $incoming[$currency]);
                        }
                    }
                }
                AppSetting::set(
                    AppSetting::KEY_SALES_DAILY_MAX_FLOAT_JSON,
                    json_encode($merged, JSON_UNESCAPED_UNICODE)
                );
            }

            return response()->json([
                'status' => 'success',
                'message' => __('messages.settings_updated'),
                'settings' => [
                    'employee_task_subtask_bonus_default' => AppSetting::getInt(
                        AppSetting::KEY_SUBTASK_BONUS_DEFAULT,
                        5
                    ),
                    'admin_fab_options' => AppSetting::get(AppSetting::KEY_ADMIN_FAB_OPTIONS, ''),
                    'sales_daily_variance_alert_threshold' => SalesDailySettings::varianceAlertThreshold(),
                    'sales_daily_max_float' => SalesDailySettings::maxFloatMap(),
                ],
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (\Throwable $e) {
            Log::error('app_settings.update_failed', ['message' => $e->getMessage()]);

            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }
}
