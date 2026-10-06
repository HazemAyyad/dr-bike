<?php

namespace App\Http\Controllers\API\Store;

use App\Models\OnlineStore\OnlineStoreCategory;

class StoreMainCategoryController extends StoreBaseController
{
    public function getAllShowMainCategories()
    {
        $rows = OnlineStoreCategory::query()
            ->where('is_active', true)
            ->whereNull('parent_id')
            ->with(['children' => fn ($query) => $query->where('is_active', true)->with(['children' => fn ($nested) => $nested->where('is_active', true)])])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (OnlineStoreCategory $category) => $this->onlineStoreCategoryPayload($category));

        return response()->json($this->rowsResponse($rows));
    }
}
