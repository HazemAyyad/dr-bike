<?php

namespace App\Http\Controllers\API\Store;

use App\Models\OnlineStore\OnlineStoreBanner;
use App\Services\OnlineStore\BannerService;
use Illuminate\Http\Request;

class StoreOnlineAdsController extends StoreBaseController
{
    public function recordClick(OnlineStoreBanner $banner)
    {
        OnlineStoreBanner::query()->whereKey($banner->getKey())->increment('click_count');

        return response()->noContent();
    }

    public function getAllAds(Request $request, BannerService $banners)
    {
        $rows = $banners->active()->map(fn ($banner) => [
            'id' => (int) $banner->id,
            'imageUrl' => $this->storefrontMediaPath($banner->image_path),
            'image_path' => $this->storefrontMediaPath($banner->image_path),
            'titleTranslations' => $banner->title_translations,
            'contentTranslations' => $banner->content_translations,
            'actionType' => (string) $banner->action_type,
            'actionTargetId' => $banner->action_target_id,
            'actionUrl' => $banner->action_url,
            'sortOrder' => (int) $banner->sort_order,
        ])->values();

        return response()->json(['rows' => $rows, 'total' => $rows->count(), 'totalNotFiltered' => $rows->count()]);
    }
}
