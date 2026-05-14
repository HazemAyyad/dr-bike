<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\AdminDeviceToken;
use App\Models\AdminNotification;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AdminNotificationCenterController extends Controller
{
    public function index(Request $request)
    {
        try {
            $query = AdminNotification::query()->orderByDesc('id');

            if ($request->boolean('unread_only')) {
                $query->where('is_read', false);
            }

            if ($request->filled('type')) {
                $query->where('type', $request->string('type'));
            }

            if ($request->filled('date_from')) {
                $query->whereDate('created_at', '>=', $request->date('date_from'));
            }

            if ($request->filled('date_to')) {
                $query->whereDate('created_at', '<=', $request->date('date_to'));
            }

            $perPage = min(max((int) $request->input('per_page', 15), 1), 100);
            $paginator = $query->paginate($perPage);

            return response()->json([
                'status' => 'success',
                'notifications' => $paginator,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function unreadCount()
    {
        try {
            $count = AdminNotification::query()->where('is_read', false)->count();

            return response()->json([
                'status' => 'success',
                'unread_count' => $count,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function markRead(Request $request, int $id)
    {
        try {
            $notification = AdminNotification::query()->findOrFail($id);
            $notification->update([
                'is_read' => true,
                'read_at' => now(),
            ]);

            return response()->json([
                'status' => 'success',
            ], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function markAllRead()
    {
        try {
            AdminNotification::query()->where('is_read', false)->update([
                'is_read' => true,
                'read_at' => now(),
            ]);

            return response()->json([
                'status' => 'success',
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function destroy(int $id)
    {
        try {
            AdminNotification::query()->whereKey($id)->delete();

            return response()->json([
                'status' => 'success',
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function storeDeviceToken(Request $request)
    {
        try {
            $data = $request->validate([
                'fcm_token' => 'required|string|max:512',
                'platform' => 'nullable|string|max:32',
                'device_name' => 'nullable|string|max:255',
            ]);

            AdminDeviceToken::query()->updateOrCreate(
                ['fcm_token' => $data['fcm_token']],
                [
                    'user_id' => $request->user()->id,
                    'platform' => $data['platform'] ?? null,
                    'device_name' => $data['device_name'] ?? null,
                    'last_seen_at' => now(),
                ]
            );

            return response()->json([
                'status' => 'success',
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function destroyDeviceToken(Request $request)
    {
        try {
            $data = $request->validate([
                'fcm_token' => 'required|string|max:512',
            ]);

            AdminDeviceToken::query()
                ->where('fcm_token', $data['fcm_token'])
                ->where('user_id', $request->user()->id)
                ->delete();

            return response()->json([
                'status' => 'success',
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }
}
