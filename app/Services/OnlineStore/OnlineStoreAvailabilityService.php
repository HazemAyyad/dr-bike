<?php

namespace App\Services\OnlineStore;

use App\Enums\SalesOrderStatus;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class OnlineStoreAvailabilityService
{
    public function outOfStockPublishedCount(array $filters = []): int
    {
        $variants = DB::table('sizes as availability_size')
            ->join('size_colors as availability_variant', 'availability_variant.sizeId', '=', 'availability_size.id')
            ->selectRaw('availability_size.itemId AS product_id, COUNT(*) AS variant_count, SUM(COALESCE(availability_variant.stock, 0)) AS physical_stock')
            ->groupBy('availability_size.itemId');
        $reserved = DB::table('sales_order_items as availability_item')
            ->join('sales_orders as availability_order', 'availability_order.id', '=', 'availability_item.sales_order_id')
            ->where('availability_order.reserves_stock', true)
            ->where('availability_item.is_hidden', false)
            ->whereIn('availability_order.status', [
                SalesOrderStatus::Unconfirmed->value,
                SalesOrderStatus::Confirmed->value,
                SalesOrderStatus::Ready->value,
                SalesOrderStatus::Postponed->value,
                SalesOrderStatus::Review->value,
            ])
            ->selectRaw("availability_item.product_id, SUM(GREATEST(GREATEST(CAST(availability_item.reserved_qty AS SIGNED), CASE WHEN availability_order.status = 'unconfirmed' AND CAST(availability_item.reserved_qty AS SIGNED) = 0 THEN CAST(availability_item.quantity AS SIGNED) ELSE CAST(availability_item.reserved_qty AS SIGNED) END) - CAST(availability_item.dispatched_qty AS SIGNED), 0)) AS reserved_qty")
            ->groupBy('availability_item.product_id');
        $query = DB::table('online_store_listings as listing')
            ->join('products as product', 'product.id', '=', 'listing.product_id')
            ->leftJoinSub($variants, 'availability_variants', 'availability_variants.product_id', '=', 'product.id')
            ->leftJoinSub($reserved, 'availability_reserved', 'availability_reserved.product_id', '=', 'product.id')
            ->whereNull('product.deleted_at')->where('listing.status', 'published')
            ->whereRaw('(CASE WHEN COALESCE(availability_variants.variant_count, 0) > 0 THEN COALESCE(availability_variants.physical_stock, 0) ELSE COALESCE(product.stock, 0) END - COALESCE(availability_reserved.reserved_qty, 0)) <= 0');
        $this->applyListingFilters($query, $filters);

        return $query->count();
    }

    private function applyListingFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['listing_id'])) {
            $query->where('listing.id', (int) $filters['listing_id']);
        }
        if (! empty($filters['category_id'])) {
            $query->whereExists(function ($membership) use ($filters) {
                $membership->selectRaw('1')->from('online_store_category_listing as availability_membership')
                    ->whereColumn('availability_membership.online_store_listing_id', 'listing.id')
                    ->where('availability_membership.online_store_category_id', (int) $filters['category_id']);
            });
        }
    }
}
