<?php

namespace App\Http\Controllers\API\Store;

use App\Models\OnlineStore\OnlineStoreReview;
use App\Models\Product;
use App\Models\User;
use App\Services\OnlineStore\OnlineStoreReviewService;
use App\Services\OnlineStore\StoreIdentityService;
use Illuminate\Http\Request;

class StoreCommentsController extends StoreBaseController
{
    public function getAllCommentsToItem(Request $request, OnlineStoreReviewService $reviews)
    {
        $productId = $request->query('ItemId', $request->input('ItemId', $request->input('itemId', $request->input('productId'))));
        $rows = [];
        if (is_numeric($productId) && Product::query()->whereKey((int) $productId)->where('isShow', true)->exists()) {
            $rows = $reviews->publishedForProduct((int) $productId)
                ->map(fn (OnlineStoreReview $review) => $reviews->legacyPayload($review))
                ->values()->all();
        }

        return response()->json([
            'rows' => $rows,
            'total' => count($rows),
            'totalNotFiltered' => count($rows),
        ]);
    }

    public function manageComment(
        Request $request,
        OnlineStoreReviewService $reviews,
        StoreIdentityService $identity,
    ) {
        $actor = $this->authenticatedActor($request, $identity);
        $data = $request->validate([
            'id' => 'nullable|integer|min:0',
            'productId' => 'required|integer|min:1',
            'rate' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:5000',
        ]);
        $payload = ['rating' => $data['rate'], 'comment' => $data['comment'] ?? null];
        if ((int) ($data['id'] ?? 0) > 0) {
            $reviews->updateOwned($actor, (int) $data['id'], $payload);
        } else {
            $product = Product::query()->findOrFail($data['productId']);
            $reviews->submit($actor, $product, $payload);
        }

        return response()->json([
            'message' => 'success',
            'isSuccess' => true,
            'error' => null,
            'isFailure' => false,
        ]);
    }

    public function submit(Request $request, OnlineStoreReviewService $reviews, StoreIdentityService $identity)
    {
        $actor = $this->authenticatedActor($request, $identity);
        $data = $request->validate([
            'product_id' => 'required|integer|min:1',
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:5000',
        ]);
        $product = Product::query()->findOrFail($data['product_id']);
        $review = $reviews->submit($actor, $product, $data);

        return response()->json(['data' => $reviews->storePayload($review)], 201);
    }

    public function own(Request $request, OnlineStoreReviewService $reviews, StoreIdentityService $identity)
    {
        $actor = $this->authenticatedActor($request, $identity);
        $rows = $reviews->ownedBy($actor)->map(fn (OnlineStoreReview $review) => $reviews->storePayload($review))->values();

        return ['data' => $rows, 'meta' => ['total' => $rows->count()]];
    }

    public function product(Request $request, int $product, OnlineStoreReviewService $reviews)
    {
        abort_unless(Product::query()->whereKey($product)->where('isShow', true)->exists(), 404);
        $rows = $reviews->publishedForProduct($product)->map(fn (OnlineStoreReview $review) => $reviews->storePayload($review))->values();

        return ['data' => $rows, 'meta' => ['total' => $rows->count()]];
    }

    private function authenticatedActor(Request $request, StoreIdentityService $identity): User
    {
        $storeUser = $this->storeUserFromRequest($request);
        if (! $storeUser) {
            abort(401, 'Unauthenticated.');
        }
        $actor = User::query()->find($storeUser->getKey());
        if (! $actor || $actor->is_blocked) {
            abort(401, 'Unauthenticated.');
        }
        $identity->activeLink($actor, 'customer');

        return $actor;
    }
}
