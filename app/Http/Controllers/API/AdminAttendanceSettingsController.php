<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Support\AttendanceSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AdminAttendanceSettingsController extends Controller
{
    public function show(Request $request)
    {
        try {
            return response()->json([
                'status' => 'success',
                'settings' => AttendanceSettings::toArray(),
            ], 200);
        } catch (\Throwable $e) {
            Log::error('attendance_settings.show_failed', ['message' => $e->getMessage()]);

            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function update(Request $request)
    {
        try {
            $settings = AttendanceSettings::updateFromArray($request->all());

            return response()->json([
                'status' => 'success',
                'message' => __('messages.settings_updated'),
                'settings' => $settings,
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (\Throwable $e) {
            Log::error('attendance_settings.update_failed', ['message' => $e->getMessage()]);

            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }
}
