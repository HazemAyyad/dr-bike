<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Support\AppUpdateSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AppUpdateController extends Controller
{
    public function check(Request $request)
    {
        try {
            $data = $request->validate([
                'app' => 'nullable|string|in:admin',
                'platform' => 'required|string|in:android,ios',
                'current_version' => 'nullable|string|max:40',
                'current_build' => 'required|integer|min:0|max:999999',
            ]);

            $platform = strtolower($data['platform']);
            $currentBuild = (int) $data['current_build'];
            $settings = AppUpdateSettings::platform($platform);

            $latestBuild = (int) $settings['latest_build'];
            $minimumBuild = (int) $settings['minimum_build'];
            $isActive = (bool) $settings['is_active'];
            $isBehindLatest = $latestBuild > 0 && $currentBuild < $latestBuild;
            $isBelowMinimum = $minimumBuild > 0 && $currentBuild < $minimumBuild;
            $hasUpdate = $isActive && ($isBehindLatest || $isBelowMinimum);
            $forceUpdate = $hasUpdate && ($isBelowMinimum || (bool) $settings['force_update']);

            return response()->json([
                'status' => 'success',
                'app' => 'admin',
                'platform' => $platform,
                'has_update' => $hasUpdate,
                'force_update' => $forceUpdate,
                'latest_version' => $settings['latest_version'],
                'latest_build' => $latestBuild,
                'minimum_build' => $minimumBuild,
                'current_version' => (string) ($data['current_version'] ?? ''),
                'current_build' => $currentBuild,
                'title' => $settings['title'],
                'message' => $settings['message'],
                'url' => $settings['url'],
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (\Throwable $e) {
            Log::error('app_update.check_failed', ['message' => $e->getMessage()]);

            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }
}
