<?php

namespace App\Services\OnlineStore;

use App\Models\OnlineStore\OnlineStoreListing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class StorefrontCatalogService
{
    public function __construct(
        private readonly ListingReadinessService $readiness,
        private readonly StoreAvailabilityService $availability,
    ) {}

    public function eligibleQuery(bool $checkProduct = true): Builder
    {
        return OnlineStoreListing::query()
            ->withPublishedReviewSummary()
            ->where('status', 'published')
            ->where('readiness_state', 'complete')
            ->when($checkProduct, fn ($query) => $query->whereHas('product', fn (Builder $product) => $product->whereNull('deleted_at')))
            ->whereExists(fn ($query) => $query->selectRaw('1')
                ->from('online_store_category_listing as membership')
                ->join('online_store_categories as category', 'category.id', '=', 'membership.online_store_category_id')
                ->whereColumn('membership.online_store_listing_id', 'online_store_listings.id')
                ->where('category.is_active', true));
    }

    public function eligible(Builder $query): Collection
    {
        $listings = $query->with('product')->get();
        $batch = app(StorefrontReadBatch::class);
        $batch->prepare($listings);
        try {
            return $listings->filter(fn (OnlineStoreListing $listing) => $this->isEligible($listing))->values();
        } finally {
            $batch->clear();
        }
    }

    public function findEligibleByProductId(int $productId): ?OnlineStoreListing
    {
        if ($productId <= 0) {
            return null;
        }

        return $this->eligible(
            $this->eligibleQuery()
                ->where('product_id', $productId)
                ->limit(1)
        )->first();
    }

    public function isEligible(OnlineStoreListing $listing): bool
    {
        if ($listing->status !== 'published' || $listing->readiness_state !== 'complete') {
            return false;
        }

        $listing->loadMissing('product');

        return $this->readiness->evaluate($listing)['state'] === 'complete'
            && $this->availability->resolve($listing->product, $listing)['visible'] === true;
    }
}
