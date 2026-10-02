<?php

namespace App\Services\OnlineStore;

use App\Models\OnlineStore\OnlineStoreListing;
use App\Models\Product;
use App\Services\SalesOrderStockService;

final class StoreAvailabilityService
{
    public function __construct(private readonly SalesOrderStockService $stock, private readonly ListingReadinessService $readiness) {}

    public function resolve(?Product $product, ?OnlineStoreListing $listing = null): array
    {
        if (! $product || ! $product->exists || $product->trashed()) {
            return ['visible' => false, 'purchasable' => false, 'available_qty' => 0, 'physical_stock' => 0, 'reserved_qty' => 0, 'variants' => []];
        }
        $rows = collect($this->stock->bulkAvailability([(int) $product->getKey()]));
        $aggregate = $rows->firstWhere('is_aggregate', true) ?? $rows->firstWhere('size_color_id', null);
        $variants = $rows->whereNotNull('size_color_id')->map(fn ($row) => [
            'size_color_id' => $row['size_color_id'], 'physical_stock' => $row['physical_stock'],
            'reserved_qty' => $row['reserved_qty'], 'available_qty' => $row['available_qty'],
            'purchasable' => $row['available_qty'] > 0,
        ])->values()->all();
        $available = $aggregate !== null ? (int) $aggregate['available_qty'] : (int) collect($variants)->sum('available_qty');
        $eligible = $listing === null || ($listing->status === 'published' && $this->readiness->evaluate($listing)['state'] === 'complete');

        return ['visible' => $eligible, 'purchasable' => $eligible && $available > 0,
            'available_qty' => $available, 'physical_stock' => (int) ($aggregate['physical_stock'] ?? 0),
            'reserved_qty' => (int) ($aggregate['reserved_qty'] ?? 0), 'variants' => $variants];
    }
}
