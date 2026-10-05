<?php

namespace App\Services\OnlineStore;

use App\Models\Product;
use Illuminate\Support\Facades\DB;

final class StorePriceResolver
{
    public function resolve(?Product $product): array
    {
        if (! $product || ! $product->exists || $product->trashed()) {
            return ['retail' => null, 'wholesale' => null, 'variants' => []];
        }
        $retail = $this->number($product->normailPrice ?? $product->price);
        $wholesale = $this->number($product->wholesalePrice);
        $variants = DB::table('sizes')->join('size_colors', 'size_colors.sizeId', '=', 'sizes.id')
            ->where('sizes.itemId', $product->getKey())->orderBy('sizes.id')->orderBy('size_colors.id')
            ->get(['size_colors.id', 'size_colors.sizeId', 'size_colors.normailPrice', 'size_colors.wholesalePrice'])
            ->map(fn ($row) => ['id' => (int) $row->id, 'size_id' => (int) $row->sizeId,
                'retail' => $this->number($row->normailPrice) ?? $retail,
                'wholesale' => $this->number($row->wholesalePrice) ?? $wholesale])->all();

        return ['retail' => $retail, 'wholesale' => $wholesale, 'variants' => $variants];
    }

    private function number(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
