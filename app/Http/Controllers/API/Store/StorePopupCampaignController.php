<?php

namespace App\Http\Controllers\API\Store;

use App\Models\OnlineStore\OnlineStorePopupCampaign;
use App\Models\OnlineStore\OnlineStorePopupEvent;
use App\Services\OnlineStore\PopupCampaignService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StorePopupCampaignController extends StoreBaseController
{
    public function event(
        Request $request,
        OnlineStorePopupCampaign $popupCampaign,
        PopupCampaignService $service,
    ): JsonResponse {
        $data = $request->validate([
            'event_type' => ['required', Rule::in(OnlineStorePopupEvent::TYPES)],
        ]);
        $service->record($popupCampaign, $request, $this->storeUserFromRequest($request), $data['event_type']);

        return response()->json(['data' => true]);
    }
}
