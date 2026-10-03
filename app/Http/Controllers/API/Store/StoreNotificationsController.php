<?php

namespace App\Http\Controllers\API\Store;

use App\Models\AdminNotification;
use App\Models\User;
use App\Services\OnlineStore\OnlineStoreNotificationService;
use App\Services\OnlineStore\StoreIdentityService;
use Illuminate\Http\Request;

class StoreNotificationsController extends StoreBaseController
{
    public function getNotifications(
        Request $request,
        OnlineStoreNotificationService $notifications,
        StoreIdentityService $identity,
    ) {
        $actor = $this->authenticatedActor($request, $identity, required: false);
        if (! $actor) {
            return response()->json(['rows' => [], 'total' => 0, 'totalNotFiltered' => 0]);
        }
        $this->rejectForeignSubmittedUser($request, $actor);
        $rows = $notifications->notificationsFor($actor)->map(fn (AdminNotification $notification) => [
            'id' => (int) $notification->getKey(),
            'isRead' => (bool) $notification->is_read,
            'title' => (string) $notification->title,
            'content' => (string) $notification->body,
            'toUser' => (string) $actor->getKey(),
            'createdAt' => $this->dateString($notification->created_at),
            'updatedAt' => $this->dateString($notification->updated_at),
        ])->values();

        return response()->json([
            'rows' => $rows,
            'total' => $rows->count(),
            'totalNotFiltered' => $rows->count(),
        ]);
    }

    public function editNotification(
        Request $request,
        OnlineStoreNotificationService $notifications,
        StoreIdentityService $identity,
    ) {
        $actor = $this->authenticatedActor($request, $identity);
        $this->rejectForeignSubmittedUser($request, $actor);
        $notificationId = $request->query('NotificationId', $request->input('NotificationId', $request->input('notificationId', $request->input('id'))));
        abort_unless(is_numeric($notificationId), 404);
        $notifications->markRead($actor, (int) $notificationId);

        return response()->json([
            'message' => 'success',
            'isSuccess' => true,
            'error' => null,
            'isFailure' => false,
        ]);
    }

    private function authenticatedActor(Request $request, StoreIdentityService $identity, bool $required = true): ?User
    {
        $storeUser = $this->storeUserFromRequest($request);
        $actor = $storeUser ? User::query()->find($storeUser->getKey()) : null;
        if (! $actor || $actor->is_blocked || $identity->activeLinks($actor)->isEmpty()) {
            if ($required) {
                abort(401, 'Unauthenticated.');
            }

            return null;
        }

        return $actor;
    }

    private function rejectForeignSubmittedUser(Request $request, User $actor): void
    {
        $submitted = $request->query('UserId', $request->input('UserId', $request->input('userId')));
        if (is_numeric($submitted) && (int) $submitted !== (int) $actor->getKey()) {
            abort(404);
        }
    }
}
