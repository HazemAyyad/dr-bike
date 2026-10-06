<?php

namespace App\Http\Controllers\API\Store;

use App\Services\OnlineStore\StorefrontCatalogService;
use Illuminate\Http\Request;

class StoreItemsController extends StoreBaseController
{
    public function __construct(private readonly StorefrontCatalogService $catalog) {}

    public function getAllItemIsMoreSales()
    {
        $query = $this->catalog->eligibleQuery()
            ->whereHas('product', fn ($product) => $product->where('isMoreSales', true))
            ->orderBy('sort_order')->orderBy('id');
        $listings = $this->catalog->eligible($query);

        if ($listings->isEmpty()) {
            $listings = $this->catalog->eligible(
                $this->catalog->eligibleQuery()->orderBy('sort_order')->orderBy('id')->limit(30)
            );
        }

        return response()->json($this->rowsResponse(
            $listings->map(fn ($listing) => $this->storefrontListingPayload($listing))
        ));
    }

    public function getAllItemByName(Request $request)
    {
        $name = trim((string) $request->query('Name', $request->input('Name', '')));
        $query = $this->catalog->eligibleQuery();
        if ($name !== '') {
            $query->where(function ($listing) use ($name) {
                $listing->where('name_translations', 'like', "%{$name}%")
                    ->orWhereHas('product', fn ($product) => $product
                        ->where('nameAr', 'like', "%{$name}%")
                        ->orWhere('nameEng', 'like', "%{$name}%")
                        ->orWhere('nameAbree', 'like', "%{$name}%")
                        ->orWhere('product_code', 'like', "%{$name}%"));
            });
        }

        return response()->json($this->rowsResponse(
            $this->catalog->eligible($query->orderBy('sort_order')->orderBy('id'))
                ->map(fn ($listing) => $this->storefrontListingPayload($listing))
        ));
    }

    public function getAllItemsShowByMainCategory(Request $request)
    {
        $categoryId = $request->query('MainCategory', $request->input('MainCategory'));
        $query = $this->catalog->eligibleQuery();
        if ($categoryId !== null && $categoryId !== '') {
            // Membership is intentionally direct. Descendant categories are selectable
            // identities of their own and are never inferred from inventory locations.
            $query->whereExists(fn ($membership) => $membership->selectRaw('1')
                ->from('online_store_category_listing as selected_membership')
                ->whereColumn('selected_membership.online_store_listing_id', 'online_store_listings.id')
                ->where('selected_membership.online_store_category_id', (int) $categoryId));
        }

        return response()->json($this->rowsResponse(
            $this->catalog->eligible($query->orderBy('sort_order')->orderBy('id'))
                ->map(fn ($listing) => $this->storefrontListingPayload($listing))
        ));
    }

    public function getItemById(Request $request)
    {
        $productId = (int) $request->query('itemId', $request->input('itemId'));
        $listing = $this->catalog->eligible(
            $this->catalog->eligibleQuery()->where('product_id', $productId)->limit(1)
        )->first();

        if (! $listing) {
            return response()->json(['message' => 'ThisItemNotFound'], 404);
        }

        return response()->json($this->storefrontListingPayload($listing));
    }

    public function getAllShowItemsBySupCatId(Request $request)
    {
        $categoryId = $request->query('supCategoryId', $request->input('supCategoryId'));
        $request->merge(['MainCategory' => $categoryId]);

        return $this->getAllItemsShowByMainCategory($request);
    }
}
