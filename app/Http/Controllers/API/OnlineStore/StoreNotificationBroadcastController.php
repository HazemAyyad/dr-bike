<?php

namespace App\Http\Controllers\API\OnlineStore;

use App\Http\Controllers\Controller;
use App\Models\OnlineStore\OnlineStoreCategory;
use App\Models\OnlineStore\OnlineStoreListing;
use App\Models\OnlineStore\OnlineStoreNotificationBroadcast;
use App\Models\User;
use App\Policies\OnlineStore\OnlineStorePolicy;
use App\Services\OnlineStore\OnlineStoreNotificationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StoreNotificationBroadcastController extends Controller
{
    private const PERMISSION = 'Online Store Content Manage';

    public function index(Request $request)
    {
        $this->authorizeRequest($request);

        return response()->json(['data' => OnlineStoreNotificationBroadcast::query()
            ->with(['creator:id,name', 'popupCampaign:id,name'])
            ->orderByDesc('id')->limit(100)->get()]);
    }

    public function store(Request $request, OnlineStoreNotificationService $notifications)
    {
        $this->authorizeRequest($request);
        $data = $request->validate([
            'title_translations' => ['required', 'array'],
            'title_translations.ar' => ['required', 'string', 'max:120'],
            'title_translations.en' => ['nullable', 'string', 'max:120'],
            'title_translations.he' => ['nullable', 'string', 'max:120'],
            'body_translations' => ['required', 'array'],
            'body_translations.ar' => ['required', 'string', 'max:500'],
            'body_translations.en' => ['nullable', 'string', 'max:500'],
            'body_translations.he' => ['nullable', 'string', 'max:500'],
            'audience_type' => ['required', Rule::in(OnlineStoreNotificationBroadcast::AUDIENCES)],
            'audience_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'destination_type' => ['required', Rule::in(OnlineStoreNotificationBroadcast::DESTINATIONS)],
            'destination_id' => ['nullable', 'integer', 'min:1'],
            'destination_url' => ['nullable', 'url:http,https', 'max:2048'],
        ]);
        if ($data['audience_type'] === 'new_users' && empty($data['audience_days'])) {
            return response()->json(['message' => 'حدد عدد أيام المستخدم الجديد.'], 422);
        }
        $internal = in_array($data['destination_type'], ['listing', 'category'], true);
        if ($internal !== isset($data['destination_id'])) {
            return response()->json(['message' => 'وجهة الإشعار غير مكتملة.'], 422);
        }
        if ($data['destination_type'] === 'listing'
            && ! OnlineStoreListing::query()->whereKey($data['destination_id'])->exists()) {
            return response()->json(['message' => 'المنتج المحدد غير موجود في المتجر.'], 422);
        }
        if ($data['destination_type'] === 'category'
            && ! OnlineStoreCategory::query()->whereKey($data['destination_id'])->exists()) {
            return response()->json(['message' => 'التصنيف المحدد غير موجود.'], 422);
        }
        if (($data['destination_type'] === 'url') !== isset($data['destination_url'])) {
            return response()->json(['message' => 'رابط الإشعار غير مكتمل.'], 422);
        }

        return response()->json(['data' => $notifications->broadcast($data, $request->user())], 201);
    }

    private function authorizeRequest(Request $request): void
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        app(OnlineStorePolicy::class)->authorize($user, self::PERMISSION)->authorize();
    }
}
