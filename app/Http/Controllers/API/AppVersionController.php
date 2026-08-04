<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\UserAppVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AppVersionController extends Controller
{
    public function seen(Request $request)
    {
        try {
            $data = $request->validate([
                'app' => 'nullable|string|in:admin',
                'platform' => 'required|string|in:android,ios,windows',
                'device_key' => 'required|string|max:120',
                'device_name' => 'nullable|string|max:255',
                'version' => 'nullable|string|max:40',
                'build' => 'required|integer|min:0|max:999999',
                'fcm_token' => 'nullable|string|max:512',
            ]);

            UserAppVersion::query()->updateOrCreate(
                [
                    'user_id' => $request->user()->id,
                    'app' => $data['app'] ?? 'admin',
                    'platform' => $data['platform'],
                    'device_key' => $data['device_key'],
                ],
                [
                    'device_name' => $data['device_name'] ?? null,
                    'version' => $data['version'] ?? null,
                    'build' => (int) $data['build'],
                    'fcm_token' => $data['fcm_token'] ?? null,
                    'last_seen_at' => now(),
                ]
            );

            return response()->json([
                'status' => 'success',
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (\Throwable $e) {
            Log::error('app_version.seen_failed', ['message' => $e->getMessage()]);

            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function report(Request $request)
    {
        try {
            $app = $request->input('app', 'admin');

            $summaryRows = UserAppVersion::query()
                ->select([
                    'app',
                    'platform',
                    'version',
                    'build',
                    DB::raw('COUNT(*) as devices_count'),
                    DB::raw('COUNT(DISTINCT user_id) as users_count'),
                    DB::raw('MAX(last_seen_at) as last_seen_at'),
                ])
                ->where('app', $app)
                ->groupBy('app', 'platform', 'version', 'build')
                ->orderByDesc('build')
                ->get();

            $summary = $summaryRows
                ->groupBy('platform')
                ->flatMap(function ($rows) {
                    return $rows
                        ->sortByDesc('build')
                        ->take(3)
                        ->values();
                })
                ->values();

            $versionKeys = $summary->map(function ($row) {
                return [
                    'platform' => $row->platform,
                    'version' => $row->version,
                    'build' => (int) $row->build,
                ];
            });

            $devicesQuery = UserAppVersion::query()
                ->with(['user:id,name,email,type'])
                ->where('app', $app);

            if ($versionKeys->isEmpty()) {
                $devicesQuery->whereRaw('1 = 0');
            } else {
                $devicesQuery->where(function ($query) use ($versionKeys) {
                    foreach ($versionKeys as $key) {
                        $query->orWhere(function ($subQuery) use ($key) {
                            $subQuery
                                ->where('platform', $key['platform'])
                                ->where('version', $key['version'])
                                ->where('build', $key['build']);
                        });
                    }
                });
            }

            $devices = $devicesQuery
                ->orderBy('platform')
                ->orderByDesc('build')
                ->orderByDesc('last_seen_at')
                ->get()
                ->map(function (UserAppVersion $row) {
                    return [
                        'user_id' => $row->user_id,
                        'user_name' => $row->user?->name,
                        'user_email' => $row->user?->email,
                        'user_type' => $row->user?->type,
                        'platform' => $row->platform,
                        'device_name' => $row->device_name,
                        'version' => $row->version,
                        'build' => $row->build,
                        'last_seen_at' => optional($row->last_seen_at)->toDateTimeString(),
                    ];
                });

            return response()->json([
                'status' => 'success',
                'summary' => $summary,
                'devices' => $devices,
            ], 200);
        } catch (\Throwable $e) {
            Log::error('app_version.report_failed', ['message' => $e->getMessage()]);

            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }
}
