<?php

namespace App\Support;

use App\Models\InventoryAdjustment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

class ProductInventoryCostStatusFilter
{
    public static function apply(Builder $query, string $status): void
    {
        if (! Schema::hasTable('inventory_cost_layers')) {
            if ($status === 'with') {
                $query->whereRaw('1 = 0');
            }

            return;
        }

        $callback = fn ($subQuery) => $subQuery
            ->selectRaw('1')->from('inventory_cost_layers')
            ->whereColumn('inventory_cost_layers.product_id', 'products.id')
            ->where('inventory_cost_layers.remaining_quantity', '>', 0);
        $hasCost = function ($scope) use ($callback) {
            $scope->whereExists($callback);
            if (Schema::hasTable('inventory_adjustments')) {
                $scope->orWhereExists(function ($reference) {
                    $reference->selectRaw('1')->from('inventory_adjustments as cost_reference')
                        ->leftJoin('size_colors as reference_variant', 'reference_variant.id', '=', 'cost_reference.size_color_id')
                        ->leftJoin('sizes as reference_size', 'reference_size.id', '=', 'reference_variant.sizeId')
                        ->whereColumn('cost_reference.product_id', 'products.id')
                        ->where('cost_reference.adjustment_type', InventoryAdjustment::TYPE_COST_REFERENCE)
                        ->where('cost_reference.new_unit_cost', '>', 0)
                        ->when(Schema::hasColumn('inventory_adjustments', 'reversed_at'), fn ($q) => $q->whereNull('cost_reference.reversed_at'))
                        ->where(function ($identity) {
                            $identity->where(function ($simple) {
                                $simple->whereNull('cost_reference.size_color_id')->where('products.stock', 0)
                                    ->whereNotExists(fn ($variants) => $variants->selectRaw('1')->from('sizes')
                                        ->join('size_colors', 'size_colors.sizeId', '=', 'sizes.id')
                                        ->whereColumn('sizes.itemId', 'products.id'));
                            })->orWhere(function ($variant) {
                                $variant->whereColumn('reference_size.itemId', 'products.id')->where('reference_variant.stock', 0);
                            });
                        });
                });
            }
        };
        if ($status === 'with') {
            $query->where($hasCost);
        } elseif ($status === 'without') {
            $query->whereNot($hasCost);
        }
    }
}
