<?php

namespace App\Http\Resources\OnlineStore;

use App\Services\OnlineStore\ListingReadinessService;
use App\Services\OnlineStore\MediaPresentationService;
use App\Services\OnlineStore\StoreAvailabilityService;
use App\Services\OnlineStore\StorePriceResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StorefrontListingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $listing = $this->resource;
        $product = $listing->product;
        $validProduct = $product && $product->exists && ! $product->trashed();
        $dynamicReadiness = app(ListingReadinessService::class)->evaluate($listing);
        $issues = $dynamicReadiness['issues'];
        if (! $validProduct && ! in_array('missing_product', $issues, true)) {
            $issues[] = 'missing_product';
        }
        $availability = app(StoreAvailabilityService::class)->resolve($product, $listing);
        if ($dynamicReadiness['state'] !== 'complete') {
            $availability['visible'] = false;
            $availability['purchasable'] = false;
        }

        return [
            'id' => $listing->id, 'product_id' => $listing->product_id, 'status' => $listing->status,
            'readiness_state' => $validProduct ? $dynamicReadiness['state'] : 'incomplete', 'readiness_issues' => $issues,
            'name_translations' => $listing->name_translations, 'description_translations' => $listing->description_translations,
            'badge_translations' => $listing->badge_translations,
            'display' => [
                'name' => $this->fallback($listing->name_translations, $product ? [$product->nameAr, $product->nameEng, $product->nameAbree] : []),
                'description' => $this->fallback($listing->description_translations, $product ? [$product->descriptionAr, $product->descriptionEng, $product->descriptionAbree] : []),
            ],
            'media' => app(MediaPresentationService::class)->resolved($listing),
            'base_prices' => app(StorePriceResolver::class)->resolve($product),
            'availability' => $availability,
            'is_featured' => $listing->is_featured, 'is_new' => $listing->is_new,
            'show_on_home' => $listing->show_on_home, 'show_as_offer' => $listing->show_as_offer,
        ];
    }

    private function fallback(?array $translations, array $productValues): ?string
    {
        foreach ([...(array) $translations, ...$productValues] as $value) {
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }
}
