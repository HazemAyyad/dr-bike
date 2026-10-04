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
                'app' => 'nullable|string|in:admin,store',
                'platform' => 'required|string|in:android,ios,windows',
                'current_version' => 'nullable|string|max:40',
                'current_build' => 'required|integer|min:0|max:999999',
            ]);

            $app = strtolower($data['app'] ?? 'admin');
            $platform = strtolower($data['platform']);
            if ($app === 'store' && $platform === 'windows') {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'platform' => ['The selected platform is invalid for the Store app.'],
                ]);
            }
            if ($app === 'store' && (! isset($data['current_version'])
                || preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', (string) $data['current_version']) !== 1)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'current_version' => ['A semantic current version is required for the Store app.'],
                ]);
            }
            $currentBuild = (int) $data['current_build'];
            $settings = AppUpdateSettings::platform($platform, $app);

            $latestBuild = (int) $settings['latest_build'];
            $minimumBuild = (int) $settings['minimum_build'];
            $isActive = (bool) $settings['is_active'];
            $isBehindLatest = $latestBuild > 0 && $currentBuild < $latestBuild;
            $isBelowMinimum = $minimumBuild > 0 && $currentBuild < $minimumBuild;
            $hasUpdate = $isActive && ($isBehindLatest || $isBelowMinimum);
            $forceUpdate = $hasUpdate && ($isBelowMinimum || (bool) $settings['force_update']);

            return response()->json([
                'status' => 'success',
                'app' => $app,
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
