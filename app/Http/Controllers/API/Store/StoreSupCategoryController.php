<?php

namespace App\Http\Controllers\API\Store;

use App\Models\OnlineStore\OnlineStoreCategory;
use Illuminate\Http\Request;

class StoreSupCategoryController extends StoreBaseController
{
    public function getAllShowSupCategories(Request $request)
    {
        $mainCategoryId = $request->query('mainCategoryId', $request->input('mainCategoryId'));

        $query = OnlineStoreCategory::query()
            ->where('is_active', true)
            ->whereNotNull('parent_id')
            ->orderBy('sort_order')
            ->orderBy('id');

        if ($mainCategoryId !== null && $mainCategoryId !== '') {
            $query->where('parent_id', (int) $mainCategoryId);
        }

        $rows = $query->get()->map(function (OnlineStoreCategory $category) {
            $payload = $this->onlineStoreCategoryPayload($category, false);
            $payload['mainCategoryId'] = $payload['parentId'];

            return $payload;
        });

        return response()->json($this->rowsResponse($rows));
    }
}
