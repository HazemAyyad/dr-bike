<?php

namespace App\Services\OnlineStore;

use App\Http\Resources\OnlineStore\StorefrontListingResource;
use App\Models\OnlineStore\OnlineStoreCategory;
use App\Models\OnlineStore\OnlineStoreListing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PublicStoreProductPageService
{
    public function __construct(private readonly StorefrontCatalogService $catalog) {}

    public function find(int $productId, Request $request): ?array
    {
        $listing = $this->catalog->findEligibleByProductId($productId);
        if (! $listing) {
            return null;
        }

        $listing->loadMissing(['product', 'mediaPresentations']);
        $categoryIds = DB::table('online_store_category_listing')
            ->where('online_store_listing_id', $listing->getKey())
            ->pluck('online_store_category_id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $categories = OnlineStoreCategory::query()
            ->whereIn('id', $categoryIds)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $product = $this->present($listing, $request, $categories->pluck('name_translations')->all());
        $related = $this->related($listing, $categoryIds, $request);
        $canonicalUrl = route('store.products.show', ['product' => $productId]);
        $product['canonical_url'] = $canonicalUrl;
        $product['app_deep_link'] = 'doctorbike-store://products/'.$productId;

        $structuredData = [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $product['name'],
            'description' => $product['description'],
            'sku' => $product['code'],
            'url' => $canonicalUrl,
            'image' => collect($product['media'])->pluck('url')->values()->all(),
            'offers' => [
                '@type' => 'Offer',
                'priceCurrency' => 'ILS',
                'price' => number_format($product['price'], 2, '.', ''),
                'availability' => $product['purchasable']
                    ? 'https://schema.org/InStock'
                    : 'https://schema.org/OutOfStock',
                'url' => $canonicalUrl,
            ],
        ];
        if ($product['review_count'] > 0) {
            $structuredData['aggregateRating'] = [
                '@type' => 'AggregateRating',
                'ratingValue' => $product['rating'],
                'reviewCount' => $product['review_count'],
                'bestRating' => 5,
                'worstRating' => 1,
            ];
        }

        return [
            'product' => $product,
            'relatedProducts' => $related,
            'structuredData' => $structuredData,
        ];
    }

    private function related(OnlineStoreListing $current, array $categoryIds, Request $request): array
    {
        $query = $this->catalog->eligibleQuery()
            ->where('online_store_listings.id', '<>', $current->getKey());
        if ($categoryIds !== []) {
            $query->whereExists(fn ($membership) => $membership
                ->selectRaw('1')
                ->from('online_store_category_listing as related_membership')
                ->whereColumn('related_membership.online_store_listing_id', 'online_store_listings.id')
                ->whereIn('related_membership.online_store_category_id', $categoryIds));
        }

        return $this->catalog->eligible(
            $query->orderByDesc('is_featured')->orderBy('sort_order')->orderBy('id')->limit(12)
        )->take(3)->map(fn (OnlineStoreListing $listing) => $this->present($listing, $request))->all();
    }

    private function present(OnlineStoreListing $listing, Request $request, array $categoryTranslations = []): array
    {
        $listing->loadMissing(['product', 'mediaPresentations']);
        $payload = (new StorefrontListingResource($listing))->toArray($request);
        $product = $listing->product;
        $names = (array) $listing->name_translations;
        $descriptions = (array) $listing->description_translations;
        $name = $this->translated($names)
            ?: trim((string) ($product->nameAr ?? $product->nameEng ?? $payload['display']['name'] ?? ''));
        $description = $this->translated($descriptions)
            ?: trim((string) ($product->descriptionAr ?? $product->descriptionEng ?? $payload['display']['description'] ?? ''));
        $media = collect($payload['media'] ?? [])->map(function (array $item) use ($name) {
            $metadata = is_array($item['media_metadata'] ?? null) ? $item['media_metadata'] : [];
            $type = (string) ($metadata['media_type'] ?? 'image');
            $mime = (string) ($metadata['mime_type'] ?? '');
            $isVideo = $type === 'video' || str_starts_with($mime, 'video/');
            $source = $isVideo
                ? ($metadata['poster_path'] ?? null)
                : ($item['path'] ?? null);

            if ($isVideo && blank($source)) {
                return null;
            }

            return [
                'url' => $this->absoluteMediaUrl($source),
                'is_main' => (bool) ($item['is_main'] ?? false),
                'is_video' => $isVideo,
                'alt' => $this->translated((array) ($metadata['alt_translations'] ?? [])) ?: $name,
            ];
        })->filter(fn ($item) => is_array($item) && filled($item['url']))->values();
        $images = $media->sortByDesc('is_main')->values();

        $retail = is_array($payload['store_prices']['retail'] ?? null)
            ? $payload['store_prices']['retail']
            : [];
        $basePrice = (float) ($retail['base'] ?? 0);
        $price = (float) ($retail['final'] ?? $basePrice);
        $discount = max(0, (float) ($retail['discount'] ?? 0));
        $reviewCount = (int) ($listing->published_reviews_count ?? 0);
        $rating = $reviewCount > 0
            ? round((float) ($listing->published_reviews_avg_rating ?? 0), 1)
            : null;
        $presentation = is_array($payload['detail_presentation'] ?? null)
            ? $payload['detail_presentation']
            : [];
        $quickSpecs = collect($presentation['quick_specs'] ?? [])->map(function ($spec) {
            if (! is_array($spec)) {
                return null;
            }
            $label = $this->translated((array) ($spec['label_translations'] ?? []));
            $value = $this->translated((array) ($spec['value_translations'] ?? []));

            return $label !== '' && $value !== '' ? ['label' => $label, 'value' => $value] : null;
        })->filter()->values()->all();
        $categoryNames = collect($categoryTranslations)
            ->map(fn ($translations) => $this->translated((array) $translations))
            ->filter()
            ->values()
            ->all();
        $availability = is_array($payload['availability'] ?? null) ? $payload['availability'] : [];
        $code = trim((string) ($product->product_code ?? ''))
            ?: trim((string) ($product->model ?? ''))
            ?: (string) $listing->product_id;
        $mainImage = data_get($images->first(), 'url') ?: asset('assets/doctor-bike-logo.png');

        return [
            'id' => (int) $listing->product_id,
            'listing_id' => (int) $listing->getKey(),
            'name' => $name,
            'description' => $description,
            'description_excerpt' => Str::limit(strip_tags($description), 180),
            'code' => $code,
            'price' => $price,
            'old_price' => $discount > 0 ? $basePrice : null,
            'discount_percent' => $basePrice > 0 && $discount > 0
                ? round(($discount / $basePrice) * 100)
                : 0,
            'available' => (bool) ($availability['visible'] ?? false),
            'purchasable' => (bool) ($availability['purchasable'] ?? false),
            'available_quantity' => max(0, (int) ($availability['available_qty'] ?? 0)),
            'rating' => $rating,
            'review_count' => $reviewCount,
            'media' => $images->all(),
            'main_image' => $mainImage,
            'quick_specs' => $quickSpecs,
            'shipping_warranty' => $this->translated((array) ($presentation['shipping_warranty_translations'] ?? [])),
            'return_policy' => $this->translated((array) ($presentation['return_policy_translations'] ?? [])),
            'categories' => $categoryNames,
            'canonical_url' => route('store.products.show', ['product' => $listing->product_id]),
        ];
    }

    private function translated(array $translations): string
    {
        foreach (['ar', 'en', 'he'] as $locale) {
            $value = trim((string) ($translations[$locale] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        foreach ($translations as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return '';
    }

    private function absoluteMediaUrl(mixed $value): ?string
    {
        $path = trim(str_replace('\\', '/', (string) $value));
        if ($path === '') {
            return null;
        }
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        $path = (string) preg_replace('#^(?:/?public/)+#i', '', $path);

        return asset(ltrim($path, '/'));
    }
}
