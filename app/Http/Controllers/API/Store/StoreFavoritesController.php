<?php

namespace App\Http\Controllers\API\Store;

use App\Models\OnlineStore\OnlineStoreFavorite;
use App\Models\User;
use App\Services\OnlineStore\StorefrontCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StoreFavoritesController extends StoreBaseController
{
    public function __construct(private readonly StorefrontCatalogService $catalog) {}

    public function index(Request $request): JsonResponse
    {
        $actor = $this->authenticatedUser($request);
        $favorites = OnlineStoreFavorite::query()
            ->where('user_id', $actor->getKey())
            ->with(['listing' => fn ($query) => $query
                ->withPublishedReviewSummary()
                ->with('product')])
            ->latest('id')
            ->get()
            ->filter(fn (OnlineStoreFavorite $favorite) => $favorite->listing && $this->catalog->isEligible($favorite->listing))
            ->values();

        return response()->json([
            'data' => [
                'listing_ids' => $favorites->pluck('online_store_listing_id')->map(fn ($id) => (int) $id)->all(),
                'items' => $favorites->map(fn (OnlineStoreFavorite $favorite) => $this->storefrontListingPayload($favorite->listing))->all(),
            ],
        ]);
    }

    public function toggle(Request $request): JsonResponse
    {
        $actor = $this->authenticatedUser($request);
        $data = $request->validate(['listing_id' => 'required|integer|min:1']);
        $listing = $this->catalog->eligible(
            $this->catalog->eligibleQuery()->whereKey((int) $data['listing_id'])->limit(1)
        )->first();
        if (! $listing) {
            return response()->json(['message' => 'Store listing is unavailable.'], 404);
        }

        $favorite = DB::transaction(function () use ($actor, $listing) {
            $query = OnlineStoreFavorite::query()
                ->where('user_id', $actor->getKey())
                ->where('online_store_listing_id', $listing->getKey());
            $existing = $query->lockForUpdate()->first();
            if ($existing) {
                $existing->delete();

                return null;
            }

            return OnlineStoreFavorite::query()->create([
                'user_id' => $actor->getKey(),
                'online_store_listing_id' => $listing->getKey(),
            ]);
        });

        return response()->json([
            'data' => [
                'listing_id' => (int) $listing->getKey(),
                'is_favorite' => $favorite !== null,
            ],
        ]);
    }

    private function authenticatedUser(Request $request): User
    {
        $storeUser = $this->storeUserFromRequest($request);
        if (! $storeUser) {
            abort(401, 'Unauthenticated.');
        }
        $user = User::query()->find($storeUser->getKey());
        if (! $user || $user->is_blocked) {
            abort(401, 'Unauthenticated.');
        }

        return $user;
    }
}
