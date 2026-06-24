<?php

namespace App\Http\Controllers\API\Store;

use App\Models\Store\StoreCategory;

class StoreMainCategoryController extends StoreBaseController
{
    public function getAllShowMainCategories()
    {
        $rows = StoreCategory::query()
            ->where('isShow', true)
            ->orderBy('sortOrder')
            ->orderBy('id')
            ->get()
            ->map(fn (StoreCategory $category) => $this->categoryPayload($category));

        return response()->json($this->rowsResponse($rows));
    }
}
