<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
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

            $data = $request->validate([
                'employee_task_subtask_bonus_default' => 'required|integer|min:0|max:9999',
                'admin_fab_options' => 'nullable|string|max:500',
            ]);

            AppSetting::set(
                AppSetting::KEY_SUBTASK_BONUS_DEFAULT,
                (int) $data['employee_task_subtask_bonus_default']
            );
            if ($request->has('admin_fab_options')) {
                AppSetting::set(
                    AppSetting::KEY_ADMIN_FAB_OPTIONS,
                    (string) ($data['admin_fab_options'] ?? '')
                );
            }

            return response()->json([
                'status' => 'success',
                'message' => __('messages.settings_updated'),
                'settings' => [
                    'employee_task_subtask_bonus_default' => (int) $data['employee_task_subtask_bonus_default'],
                    'admin_fab_options' => AppSetting::get(AppSetting::KEY_ADMIN_FAB_OPTIONS, ''),
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
