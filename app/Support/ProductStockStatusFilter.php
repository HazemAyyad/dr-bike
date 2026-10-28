<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

class ProductStockStatusFilter
{
    public static function apply(Builder $query, ?string $status): Builder
    {
        // products.stock is the displayed total, synchronized from variants.
        return match ($status) {
            'zero' => $query->where('products.stock', '=', 0),
            'negative' => $query->where('products.stock', '<', 0),
            default => $query,
        };
    }
}
