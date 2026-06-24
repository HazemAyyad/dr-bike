<?php

namespace App\Http\Controllers\API\Store;

use App\Models\Store\StoreSubCategory;
use Illuminate\Http\Request;

class StoreSupCategoryController extends StoreBaseController
{
    public function getAllShowSupCategories(Request $request)
    {
        $mainCategoryId = $request->query('mainCategoryId', $request->input('mainCategoryId'));

        $query = StoreSubCategory::query()
            ->where('isShow', true)
            ->orderBy('sortOrder')
            ->orderBy('id');

        if ($mainCategoryId !== null && $mainCategoryId !== '') {
            $query->where('mainCategoryId', (int) $mainCategoryId);
        }

        $rows = $query->get()->map(fn (StoreSubCategory $category) => $this->subCategoryPayload($category));

        return response()->json($this->rowsResponse($rows));
    }
}
