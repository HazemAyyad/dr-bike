<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Size;
use App\Models\SizeColor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseVariantService
{
    /** Add master data only; receiving the purchase owns all stock changes. */
    public function create(int $productId, string $sizeLabel, string $colorLabel, float $retailPrice): SizeColor
    {
        $sizeLabel = trim($sizeLabel);
        $colorLabel = trim($colorLabel);
        if ($sizeLabel === '' || $colorLabel === '') {
            throw ValidationException::withMessages(['size' => 'أدخل الحجم واللون.']);
        }

        return DB::transaction(function () use ($productId, $sizeLabel, $colorLabel, $retailPrice) {
            $product = Product::query()->lockForUpdate()->findOrFail($productId);
            $sameLabel = fn ($a, $b) => mb_strtolower(trim((string) $a)) === mb_strtolower($b);
            $size = $product->sizes()->get()->first(fn ($row) => $sameLabel($row->size, $sizeLabel));
            if (! $size) {
                $size = new Size(['itemId' => $product->id, 'size' => $sizeLabel]);
                // The tables auto-increment, while the legacy models disable ID retrieval.
                $size->incrementing = true;
                $size->save();
            }

            $variant = $size->colorSizes()->get()->first(fn ($row) => $sameLabel($row->colorAr, $colorLabel));
            if (! $variant) {
                $variant = new SizeColor([
                    'sizeId' => $size->id,
                    'colorAr' => $colorLabel,
                    'colorEn' => '',
                    'colorAbbr' => '',
                    'normailPrice' => $retailPrice,
                    'wholesalePrice' => (float) ($product->wholesalePrice ?? 0),
                    'discount' => 0,
                    'stock' => 0,
                ]);
                $variant->incrementing = true;
                $variant->save();
            }

            return $variant->load('size');
        }, 3);
    }
}
