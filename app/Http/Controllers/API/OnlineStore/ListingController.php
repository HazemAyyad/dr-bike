<?php

namespace App\Http\Controllers\API\OnlineStore;

use App\Http\Controllers\Controller;
use App\Http\Requests\OnlineStore\ManageListingRequest;
use App\Http\Resources\OnlineStore\OnlineStoreListingResource;
use App\Models\OnlineStore\OnlineStoreListing;
use App\Models\Product;
use App\Services\OnlineStore\ListingLifecycleService;
use App\Services\OnlineStore\ListingReadinessService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ListingController extends Controller
{
    public function index(ManageListingRequest $request)
    {
        return OnlineStoreListingResource::collection(OnlineStoreListing::query()->with('product')->orderBy('sort_order')->paginate());
    }

    public function show(ManageListingRequest $request, OnlineStoreListing $listing): OnlineStoreListingResource
    {
        return new OnlineStoreListingResource($listing->load('product'));
    }

    public function store(ManageListingRequest $request, ListingLifecycleService $lifecycle): OnlineStoreListingResource|JsonResponse
    {
        try {
            $listing = DB::transaction(function () use ($request, $lifecycle) {
                $listing = new OnlineStoreListing($request->safe()->except(['normailPrice', 'wholesalePrice', 'price', 'stock', 'base_price', 'base_prices']));
                $listing->status = 'draft';
                $listing->readiness_state = 'incomplete';
                $listing->created_by = $request->user()->getKey();
                $listing->updated_by = $request->user()->getKey();
                $listing->save();

                return $lifecycle->refresh($listing, $request->user());
            });
        } catch (QueryException $exception) {
            if (in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
                return response()->json(['message' => 'This Product already has an Online Store listing.'], 409);
            }
            throw $exception;
        }

        return (new OnlineStoreListingResource($listing))->response()->setStatusCode(201);
    }

    public function update(ManageListingRequest $request, OnlineStoreListing $listing, ListingLifecycleService $lifecycle): OnlineStoreListingResource
    {
        $listing->fill($request->validated());
        $listing->updated_by = $request->user()->getKey();
        $listing->save();

        return new OnlineStoreListingResource($lifecycle->refresh($listing, $request->user()));
    }

    public function transition(ManageListingRequest $request, OnlineStoreListing $listing, ListingLifecycleService $lifecycle): OnlineStoreListingResource
    {
        return new OnlineStoreListingResource($lifecycle->transition($listing, $request->validated('status'), $request->user()));
    }

    public function readiness(ManageListingRequest $request, Product $product, ListingReadinessService $readiness): JsonResponse
    {
        $listing = OnlineStoreListing::query()->where('product_id', $product->getKey())->first();
        $variantStock = DB::table('sizes')->join('size_colors', 'size_colors.sizeId', '=', 'sizes.id')
            ->where('sizes.itemId', $product->getKey())->sum('size_colors.stock');
        $hasVariants = DB::table('sizes')->where('itemId', $product->getKey())->exists();
        $sourceMedia = [
            'view_images' => DB::table('view_image_products')->where('itemId', $product->getKey())->whereNotNull('imageUrl')->where('imageUrl', '<>', '')->count(),
            'normal_images' => DB::table('normal_image_products')->where('itemId', $product->getKey())->whereNotNull('imageUrl')->where('imageUrl', '<>', '')->count(),
            'images_3d' => DB::table('image3d_products')->where('itemId', $product->getKey())->whereNotNull('imageUrl')->where('imageUrl', '<>', '')->count(),
        ];

        return response()->json(['data' => [
            ...$readiness->evaluateForProduct($product, $listing),
            'product_id' => $product->getKey(), 'listing_id' => $listing?->getKey(),
            'base_prices' => ['retail' => $product->normailPrice, 'wholesale' => $product->wholesalePrice],
            'availability' => ['stock' => (int) ($hasVariants ? $variantStock : ($product->stock ?? 0))],
            'source_media' => $sourceMedia,
        ]]);
    }
}
