<?php

namespace App\Services\OnlineStore;

use App\Models\OnlineStore\OnlineStoreListing;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

final class ListingReadinessService
{
    public function evaluate(OnlineStoreListing $listing): array
    {
        return $this->evaluateForProduct($listing->product, $listing);
    }

    public function evaluateForProduct(?Product $product, ?OnlineStoreListing $listing = null): array
    {
        $issues = [];
        if (! $product || ! $product->exists) {
            $issues[] = 'missing_product';

            return ['state' => 'incomplete', 'issues' => $issues];
        }

        if (! $this->hasText($listing?->name_translations) && ! $this->hasText([$product->nameAr, $product->nameEng, $product->nameAbree])) {
            $issues[] = 'missing_display_name';
        }
        if (! $this->hasText($listing?->description_translations) && ! $this->hasText([$product->descriptionAr, $product->descriptionEng, $product->descriptionAbree])) {
            $issues[] = 'missing_description';
        }
        if (! $listing || ! $this->hasUsableMainMedia($listing, $product)) {
            $issues[] = 'missing_main_media';
        }
        if (! $listing || ! DB::table('online_store_category_listing as membership')
            ->join('online_store_categories as category', 'category.id', '=', 'membership.online_store_category_id')
            ->where('membership.online_store_listing_id', $listing->getKey())->where('category.is_active', true)->exists()) {
            $issues[] = 'missing_active_category';
        }
        if (! $this->hasAuthoritativePrice($product)) {
            $issues[] = 'missing_authoritative_price';
        }

        return ['state' => $issues === [] ? 'complete' : 'incomplete', 'issues' => $issues];
    }

    private function hasText(mixed $value): bool
    {
        foreach ((array) $value as $item) {
            if (is_string($item) && trim($item) !== '') {
                return true;
            }
        }

        return false;
    }

    private function hasAuthoritativePrice(Product $product): bool
    {
        if ((float) ($product->normailPrice ?? 0) > 0 || (float) ($product->wholesalePrice ?? 0) > 0) {
            return true;
        }

        return DB::table('sizes')->join('size_colors', 'size_colors.sizeId', '=', 'sizes.id')
            ->where('sizes.itemId', $product->getKey())
            ->where(fn ($query) => $query->where('size_colors.normailPrice', '>', 0)->orWhere('size_colors.wholesalePrice', '>', 0))
            ->exists();
    }

    private function hasUsableMainMedia(OnlineStoreListing $listing, Product $product): bool
    {
        $media = DB::table('online_store_media_presentations')
            ->where('online_store_listing_id', $listing->getKey())->where('is_main', true)->where('is_visible', true)->get();

        foreach ($media as $item) {
            if ($item->source_type === 'store_specific' && trim((string) $item->store_media_path) !== '') {
                return true;
            }
            $table = match ($item->source_type) {
                'view_image' => 'view_image_products', 'normal_image' => 'normal_image_products',
                'image3d' => 'image3d_products', default => null,
            };
            if ($table && DB::table($table)->where('id', $item->source_id)->where('itemId', $product->getKey())
                ->whereNotNull('imageUrl')->where('imageUrl', '<>', '')->exists()) {
                return true;
            }
            if ($item->source_type === 'variant' && DB::table('size_colors')->join('sizes', 'sizes.id', '=', 'size_colors.sizeId')
                ->where('size_colors.id', $item->source_id)->where('sizes.itemId', $product->getKey())
                ->whereNotNull('size_colors.image_url')->where('size_colors.image_url', '<>', '')->exists()) {
                return true;
            }
        }

        return false;
    }
}
