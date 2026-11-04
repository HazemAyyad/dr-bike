<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

class ProductSaleStatusFilter
{
    public static function apply(Builder $query, ?string $status): Builder
    {
        return match ($status) {
            'allowed' => $query->where('products.is_sale_blocked', false),
            'blocked' => $query->where('products.is_sale_blocked', true),
            default => $query,
        };
    }
}
