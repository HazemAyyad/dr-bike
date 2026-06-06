<?php

namespace App\Support;

use App\Models\Product;
use Illuminate\Support\Collection;

class ProductImageResolver
{
    private const INVALID_TOKENS = [
        'no image',
        'no img',
        'no images',
        'no image files',
        'no admin image',
        'null',
        'undefined',
        'none',
        '-',
        'n/a',
    ];

    public static function isValidUrl(?string $url): bool
    {
        $url = trim((string) $url);

        if ($url === '') {
            return false;
        }

        return ! in_array(strtolower($url), self::INVALID_TOKENS, true);
    }

    public static function urlFromRecord(mixed $img): string
    {
        if ($img === null) {
            return '';
        }

        if (is_string($img)) {
            return trim($img);
        }

        $candidates = [
            $img->imageUrl ?? null,
            $img->image_url ?? null,
            $img->ImageUrl ?? null,
            is_object($img) && method_exists($img, 'getAttribute')
                ? $img->getAttribute('image_url')
                : null,
            is_object($img) && method_exists($img, 'getAttribute')
                ? $img->getAttribute('ImageUrl')
                : null,
            $img->url ?? null,
        ];

        foreach ($candidates as $candidate) {
            $url = trim((string) $candidate);
            if (self::isValidUrl($url)) {
                return $url;
            }
        }

        return '';
    }

    public static function preferredUrl(?Product $product): string
    {
        if ($product === null) {
            return 'no image';
        }

        foreach ([$product->viewImages, $product->normalImages, $product->image3d] as $images) {
            foreach ($images as $img) {
                $url = self::urlFromRecord($img);
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
            'product_viewImages' => self::mapValidUrls($product->viewImages)->all(),
            'product_normalImages' => self::mapValidUrls($product->normalImages)->all(),
            'product_image3d' => self::mapValidUrls($product->image3d)->all(),
        ];
    }

    private static function mapValidUrls(Collection $images): Collection
    {
        return $images
            ->map(fn ($img) => self::urlFromRecord($img))
            ->filter(fn ($url) => self::isValidUrl($url))
            ->map(fn ($url) => ApiImageUrl::normalize($url))
            ->values();
    }
}
