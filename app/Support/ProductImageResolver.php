<?php

namespace App\Support;

use App\Models\Product;
use Illuminate\Support\Collection;

class ProductImageResolver
{
    public static function isValidUrl(?string $url): bool
    {
        $url = trim((string) $url);

        if ($url === '') {
            return false;
        }

        $lower = strtolower($url);

        return ! in_array($lower, ['no image', 'null', 'undefined', 'none'], true);
    }

    public static function preferredUrl(?Product $product): string
    {
        if ($product === null) {
            return 'no image';
        }

        foreach ([$product->viewImages, $product->normalImages, $product->image3d] as $images) {
            foreach ($images as $img) {
                $url = trim((string) ($img->imageUrl ?? ''));
                if (self::isValidUrl($url)) {
                    return ApiImageUrl::normalize($url);
                }
            }
        }

        return 'no image';
    }

    /**
     * @return array{product_image: string, product_viewImages: Collection, product_normalImages: Collection, product_image3d: Collection}
     */
    public static function formatForList(Product $product): array
    {
        return [
            'product_image' => self::preferredUrl($product),
            'product_viewImages' => self::mapValidUrls($product->viewImages),
            'product_normalImages' => self::mapValidUrls($product->normalImages),
            'product_image3d' => self::mapValidUrls($product->image3d),
        ];
    }

    private static function mapValidUrls(Collection $images): Collection
    {
        return $images
            ->map(fn ($img) => trim((string) ($img->imageUrl ?? '')))
            ->filter(fn ($url) => self::isValidUrl($url))
            ->map(fn ($url) => ApiImageUrl::normalize($url))
            ->values();
    }
}
