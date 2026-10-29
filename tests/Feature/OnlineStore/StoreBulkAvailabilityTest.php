<?php

namespace Tests\Feature\OnlineStore;

use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Services\SalesOrderStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\OnlineStoreFixtureFactory;
use Tests\TestCase;

class StoreBulkAvailabilityTest extends TestCase
{
    use RefreshDatabase {
        refreshTestDatabase as private refreshDisposableDatabase;
    }

    protected function refreshTestDatabase(): void
    {
        $this->requireDisposableDatabase();
        if (filter_var(env('ONLINE_STORE_PREMIGRATED_SCHEMA_READY', false), FILTER_VALIDATE_BOOL)) {
            $this->beginDatabaseTransaction();

            return;
        }
        $this->refreshDisposableDatabase();
    }

    public function test_bulk_reads_match_individual_authority_with_variants_dispatch_hidden_lines_and_excluded_order(): void
    {
        $product = OnlineStoreFixtureFactory::createProduct(['stock' => 9]);
        $first = OnlineStoreFixtureFactory::createVariant($product, [], ['stock' => 3])['variant'];
        $second = OnlineStoreFixtureFactory::createVariant($product, [], ['stock' => 6])['variant'];
        $simple = OnlineStoreFixtureFactory::createProduct(['stock' => 7]);
        $service = app(SalesOrderStockService::class);
        $orders = [];
        foreach (['unconfirmed', 'confirmed', 'delivered'] as $index => $status) {
            $order = SalesOrder::query()->forceCreate(['serial_number' => 'BULK-'.$index, 'status' => $status,
                'reserves_stock' => true, 'subtotal' => 0, 'discount' => 0, 'calculated_total' => 0, 'total' => 0]);
            $orders[] = $order;
            foreach ([[$product, $first->id, false], [$product, $second->id, true], [$simple, null, false]] as [$itemProduct, $variantId, $hidden]) {
                SalesOrderItem::query()->forceCreate(['sales_order_id' => $order->id, 'product_id' => $itemProduct->id,
                    'size_color_id' => $variantId, 'product_name' => 'Bulk fixture', 'quantity' => 2,
                    'reserved_qty' => $status === 'unconfirmed' ? 0 : 2, 'dispatched_qty' => $status === 'confirmed' ? 1 : 0,
                    'delivered_qty' => 0, 'returned_qty' => 0, 'unit_price' => 1, 'line_total' => 2, 'is_hidden' => $hidden]);
            }
        }
        foreach ([null, (int) $orders[0]->id] as $excluded) {
            $rows = $service->bulkAvailability([$product->id, $simple->id], $excluded);
            foreach ($rows as $row) {
                $variant = $row['size_color_id'];
                $expected = $variant !== null
                    ? $service->reservedQuantity($row['product_id'], $variant, $excluded)
                    : $service->reservedQuantityForProduct($row['product_id'], $excluded);
                $this->assertSame($expected, $row['reserved_qty']);
                $this->assertSame(max(0, $row['physical_stock'] - $expected), $row['available_qty']);
            }
        }
    }
}
