<?php

namespace App\Http\Resources\OnlineStore;

use App\Services\OnlineStore\StoreAvailabilityService;
use App\Services\OnlineStore\StorePriceResolver;
use App\Services\OnlineStore\MediaPresentationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OnlineStoreListingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $product = $this->resource->product;
        $hasValidProduct = $product !== null && $product->exists && ! $product->trashed();
        $readinessIssues = $this->readinessIssues($hasValidProduct);
        $basePrices = $hasValidProduct
            ? app(StorePriceResolver::class)->resolve($product)
            : ['retail' => null, 'wholesale' => null, 'variants' => []];
        $availability = $hasValidProduct
            ? app(StoreAvailabilityService::class)->resolve($product, $this->resource)
            : ['visible' => false, 'purchasable' => false, 'available_qty' => 0, 'physical_stock' => 0, 'reserved_qty' => 0, 'variants' => []];

        return [
            'id' => $this->id, 'product_id' => $this->product_id, 'status' => $this->status,
            'name_translations' => $this->name_translations,
            'description_translations' => $this->description_translations,
            'badge_translations' => $this->badge_translations,
            'detail_presentation' => $this->detail_presentation,
            'display' => [
                'name' => $this->fallback($this->name_translations, $product ? [$product->nameAr, $product->nameEng, $product->nameAbree] : []),
                'description' => $this->fallback($this->description_translations, $product ? [$product->descriptionAr, $product->descriptionEng, $product->descriptionAbree] : []),
            ],
            'is_featured' => $this->is_featured, 'is_new' => $this->is_new,
            'show_on_home' => $this->show_on_home, 'show_as_offer' => $this->show_as_offer,
            'sort_order' => $this->sort_order,
            'online_stock_limit' => $this->online_stock_limit,
            'view_count' => (int) $this->view_count,
            'product' => $product ? [
                'id' => (int) $product->getKey(),
                'code' => (string) ($product->product_code ?? ''),
                'name_translations' => ['ar' => $product->nameAr, 'en' => $product->nameEng, 'he' => $product->nameAbree],
                'description_translations' => ['ar' => $product->descriptionAr, 'en' => $product->descriptionEng, 'he' => $product->descriptionAbree],
            ] : null,
            'media' => $hasValidProduct ? app(MediaPresentationService::class)->adminPresentation($this->resource) : [],
            'readiness_state' => $hasValidProduct ? $this->readiness_state : 'incomplete',
            'readiness_issues' => $readinessIssues,
            'product_archived' => $product?->trashed() ?? false,
            'base_prices' => $basePrices,
            'availability' => $availability,
            'published_at' => $this->published_at, 'hidden_at' => $this->hidden_at,
        ];
    }

    private function readinessIssues(bool $hasValidProduct): array
    {
        $issues = $this->readiness_issues ?? [];

        if (! $hasValidProduct && ! in_array('missing_product', $issues, true)) {
            $issues[] = 'missing_product';
        }

        return $issues;
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
