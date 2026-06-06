<?php

namespace App\Support;

use App\Models\Product;

class ProductImageResolver
{
    public static function preferredUrl(?Product $product): string
    {
        if ($product === null) {
            return 'no image';
        }

        $image = $product->viewImages->first()
            ?? $product->normalImages->first()
            ?? $product->image3d->first();

        return $image
            ? ApiImageUrl::normalize($image->imageUrl)
            : 'no image';
    }

    /**
     * @return array{product_image: string, product_viewImages: \Illuminate\Support\Collection, product_normalImages: \Illuminate\Support\Collection, product_image3d: \Illuminate\Support\Collection}
     */
    public static function formatForList(Product $product): array
    {
        return [
            'product_image' => self::preferredUrl($product),
            'product_viewImages' => $product->viewImages
                ->map(fn ($img) => ApiImageUrl::normalize($img->imageUrl))
                ->values(),
            'product_normalImages' => $product->normalImages
                ->map(fn ($img) => ApiImageUrl::normalize($img->imageUrl))
                ->values(),
            'product_image3d' => $product->image3d
                ->map(fn ($img) => ApiImageUrl::normalize($img->imageUrl))
                ->values(),
        ];
    }
}
