<?php

namespace App\Http\Controllers\API\Store;

use App\Services\OnlineStore\StorefrontCatalogService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class StoreItemsController extends StoreBaseController
{
    public function __construct(private readonly StorefrontCatalogService $catalog) {}

    public function getAllItemIsMoreSales(Request $request)
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

        return $this->catalogResponse($listings, $request);
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

        return $this->catalogResponse(
            $this->catalog->eligible($query->orderBy('sort_order')->orderBy('id')),
            $request,
        );
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

        return $this->catalogResponse(
            $this->catalog->eligible($query->orderBy('sort_order')->orderBy('id')),
            $request,
        );
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

    private function catalogResponse(Collection $listings, Request $request)
    {
        $rows = $listings->map(fn ($listing) => $this->storefrontListingPayload($listing));
        $minimum = $this->numberInput($request, 'minPrice', 'min_price');
        $maximum = $this->numberInput($request, 'maxPrice', 'max_price');
        if ($minimum !== null) {
            $rows = $rows->filter(fn (array $row) => (float) $row['normailPrice'] >= $minimum);
        }
        if ($maximum !== null) {
            $rows = $rows->filter(fn (array $row) => (float) $row['normailPrice'] <= $maximum);
        }
        if ($this->booleanInput($request, 'availableOnly', 'available_only')) {
            $rows = $rows->filter(fn (array $row) => (bool) $row['purchasable']);
        }
        if ($this->booleanInput($request, 'onSale', 'on_sale')) {
            $rows = $rows->filter(fn (array $row) => (float) $row['discount'] > 0);
        }

        $sort = (string) $request->input('sort', $request->query('sort', 'recommended'));
        $rows = match ($sort) {
            'price_asc' => $rows->sortBy(fn (array $row) => (float) $row['normailPrice'], SORT_NUMERIC),
            'price_desc' => $rows->sortByDesc(fn (array $row) => (float) $row['normailPrice'], SORT_NUMERIC),
            'newest' => $rows->sortByDesc(fn (array $row) => $row['dateAdd'] ?? ''),
            'name' => $rows->sortBy(fn (array $row) => mb_strtolower((string) $row['nameAr'])),
            default => $rows,
        };

        return response()->json($this->rowsResponse($rows->values()));
    }

    private function numberInput(Request $request, string $camel, string $snake): ?float
    {
        $value = $request->query($camel, $request->input($camel, $request->query($snake, $request->input($snake))));
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return max(0, (float) $value);
    }

    private function booleanInput(Request $request, string $camel, string $snake): bool
    {
        $value = $request->query($camel, $request->input($camel, $request->query($snake, $request->input($snake, false))));

        return filter_var($value, FILTER_VALIDATE_BOOL);
    }
}
