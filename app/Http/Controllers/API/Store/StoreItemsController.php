<?php

namespace App\Http\Controllers\API\Store;

use App\Models\Store\StoreProduct;
use Illuminate\Http\Request;

class StoreItemsController extends StoreBaseController
{
    public function getAllItemIsMoreSales()
    {
        $products = $this->baseProductQuery()
            ->where('isMoreSales', true)
            ->get();

        if ($products->isEmpty()) {
            $products = $this->baseProductQuery()
                ->limit(30)
                ->get();
        }

        $rows = $products
            ->map(fn (StoreProduct $product) => $this->productPayload($product));

        return response()->json($this->rowsResponse($rows));
    }

    public function getAllItemByName(Request $request)
    {
        $name = trim((string) $request->query('Name', $request->input('Name', '')));

        $query = $this->baseProductQuery();
        if ($name !== '') {
            $query->where(function ($q) use ($name) {
                $q->where('nameAr', 'like', "%{$name}%")
                    ->orWhere('nameEng', 'like', "%{$name}%")
                    ->orWhere('nameAbree', 'like', "%{$name}%")
                    ->orWhere('product_code', 'like', "%{$name}%");
            });
        }

        $rows = $query->get()->map(fn (StoreProduct $product) => $this->productPayload($product));

        return response()->json($this->rowsResponse($rows));
    }

    public function getAllItemsShowByMainCategory(Request $request)
    {
        $mainCategoryId = $request->query('MainCategory', $request->input('MainCategory'));

        $query = $this->baseProductQuery();
        if ($mainCategoryId !== null && $mainCategoryId !== '') {
            $query->where('category_id', (int) $mainCategoryId);
        }

        $rows = $query->get()->map(fn (StoreProduct $product) => $this->productPayload($product));

        return response()->json($this->rowsResponse($rows));
    }

    public function getItemById(Request $request)
    {
        $itemId = $request->query('itemId', $request->input('itemId'));

        $product = $this->baseProductQuery()
            ->where('id', (int) $itemId)
            ->first();

        if (! $product) {
            return response()->json(['message' => 'ThisItemNotFound'], 404);
        }

        return response()->json($this->productPayload($product));
    }

    public function getAllShowItemsBySupCatId(Request $request)
    {
        $supCategoryId = $request->query('supCategoryId', $request->input('supCategoryId'));

        $query = $this->baseProductQuery();
        if ($supCategoryId !== null && $supCategoryId !== '') {
            $query->whereHas('subCategories', fn ($q) => $q->where('sub_categories.id', (int) $supCategoryId));
        }

        $rows = $query->get()->map(fn (StoreProduct $product) => $this->productPayload($product));

        return response()->json($this->rowsResponse($rows));
    }

    private function baseProductQuery()
    {
        return StoreProduct::query()
            ->with(['subCategories', 'normalImages', 'viewImages', 'image3d', 'sizes.colors'])
            ->where('isShow', true)
            ->orderByDesc('id');
    }
}
