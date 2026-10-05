<?php

namespace App\Http\Controllers\API\OnlineStore;

use App\Http\Controllers\Controller;
use App\Models\OnlineStore\OnlineStorePromotion;
use App\Models\User;
use App\Policies\OnlineStore\OnlineStorePolicy;
use App\Services\OnlineStore\OnlineStoreNotificationService;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function promotion(
        Request $request,
        OnlineStorePromotion $promotion,
        OnlineStoreNotificationService $notifications,
    ) {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        app(OnlineStorePolicy::class)->authorize($user, 'Online Store Content Manage')->authorize();

        return ['data' => $notifications->broadcastPromotion($promotion)];
    }
}
