<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\AdminDeviceToken;
use App\Models\AdminNotification;
use App\Models\AdminNotificationReceipt;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AdminNotificationCenterController extends Controller
{
    public function index(Request $request)
    {
        try {
            $query = AdminNotification::query()
                ->with(['receipts' => fn ($q) => $q->where('user_id', $request->user()->id)])
                ->where(function ($q) use ($request) {
                    $q->whereNull('recipient_user_id')
                        ->orWhere('recipient_user_id', $request->user()->id);
                })
                ->whereDoesntHave('receipts', fn ($q) => $q
                    ->where('user_id', $request->user()->id)
                    ->whereNotNull('deleted_at'))
                ->where(function ($q) {
                    $q->whereNull('data->_in_app_hidden')->orWhere('data->_in_app_hidden', '!=', '1');
                })
                ->orderByDesc('id');

            if ($request->boolean('unread_only')) {
                $query->where(function ($q) use ($request) {
                    $q->where(function ($targeted) {
                        $targeted->whereNotNull('recipient_user_id')->where('is_read', false);
                    })->orWhere(function ($global) use ($request) {
                        $global->whereNull('recipient_user_id')
                            ->whereDoesntHave('receipts', fn ($receipt) => $receipt
                                ->where('user_id', $request->user()->id)
                                ->whereNotNull('read_at'));
                    });
                });
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
            $paginator->getCollection()->transform(function (AdminNotification $notification) {
                if ($notification->recipient_user_id === null) {
                    $receipt = $notification->receipts->first();
                    $notification->setAttribute('is_read', $receipt?->read_at !== null);
                    $notification->setAttribute('read_at', $receipt?->read_at);
                }
                $notification->unsetRelation('receipts');

                return $notification;
            });

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
            $userId = (int) auth()->id();
            $count = AdminNotification::query()
                ->where(function ($q) {
                    $q->whereNull('recipient_user_id')
                        ->orWhere('recipient_user_id', auth()->id());
                })
                ->whereDoesntHave('receipts', fn ($q) => $q
                    ->where('user_id', $userId)
                    ->whereNotNull('deleted_at'))
                ->where(function ($q) {
                    $q->whereNull('data->_in_app_hidden')->orWhere('data->_in_app_hidden', '!=', '1');
                })
                ->where(function ($q) use ($userId) {
                    $q->where(function ($targeted) {
                        $targeted->whereNotNull('recipient_user_id')->where('is_read', false);
                    })->orWhere(function ($global) use ($userId) {
                        $global->whereNull('recipient_user_id')
                            ->whereDoesntHave('receipts', fn ($receipt) => $receipt
                                ->where('user_id', $userId)
                                ->whereNotNull('read_at'));
                    });
                })
                ->count();

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
            $notification = AdminNotification::query()
                ->where(function ($q) use ($request) {
                    $q->whereNull('recipient_user_id')
                        ->orWhere('recipient_user_id', $request->user()->id);
                })
                ->findOrFail($id);
            if ($notification->recipient_user_id === null) {
                AdminNotificationReceipt::query()->updateOrCreate(
                    ['admin_notification_id' => $notification->id, 'user_id' => $request->user()->id],
                    ['seen_at' => now(), 'read_at' => now(), 'opened_at' => now(), 'deleted_at' => null]
                );
            } else {
                $notification->update(['is_read' => true, 'read_at' => now()]);
            }

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
            $userId = (int) auth()->id();
            AdminNotification::query()->where('recipient_user_id', $userId)
                ->where('is_read', false)
                ->update(['is_read' => true, 'read_at' => now()]);

            AdminNotification::query()->whereNull('recipient_user_id')
                ->whereDoesntHave('receipts', fn ($q) => $q
                    ->where('user_id', $userId)->whereNotNull('deleted_at'))
                ->pluck('id')
                ->each(fn ($id) => AdminNotificationReceipt::query()->updateOrCreate(
                    ['admin_notification_id' => $id, 'user_id' => $userId],
                    ['seen_at' => now(), 'read_at' => now(), 'deleted_at' => null]
                ));

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

    public function destroy(Request $request, int $id)
    {
        try {
            $notification = AdminNotification::query()
                ->where(function ($q) use ($request) {
                    $q->whereNull('recipient_user_id')
                        ->orWhere('recipient_user_id', $request->user()->id);
                })
                ->findOrFail($id);

            if ($notification->recipient_user_id === null) {
                AdminNotificationReceipt::query()->updateOrCreate(
                    ['admin_notification_id' => $notification->id, 'user_id' => $request->user()->id],
                    ['deleted_at' => now()]
                );
            } else {
                $notification->delete();
            }

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

            $request->user()->forceFill(['fcm_token' => $data['fcm_token']])->save();

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
