<?php

namespace Tests\Feature;

use App\Models\DeliveryCompany;
use App\Models\InventoryCostAllocation;
use App\Models\Product;
use App\Models\ProductStockMovement;
use App\Models\SalesOrderMedia;
use App\Models\User;
use App\Services\InventoryCostingService;
use App\Services\SalesDailySessionService;
use App\Services\SalesOrderFulfillmentService;
use App\Services\SalesOrderNotificationService;
use App\Services\SalesOrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SalesOrderDeliveryStockTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->refreshStoreSchema();
        Http::preventStrayRequests();
        $this->app->instance(SalesOrderNotificationService::class,
            Mockery::mock(SalesOrderNotificationService::class)->shouldIgnoreMissing());
    }

    public static function stockBalances(): array
    {
        return [
            'already negative' => [-139],
            'empty' => [0],
            'positive' => [5],
        ];
    }

    #[DataProvider('stockBalances')]
    public function test_delivery_preparation_and_handover_do_not_repeat_stock_or_cost_movements(int $stock): void
    {
        $user = User::factory()->create(['type' => 'admin']);
        $product = $this->product(min(0, $stock));
        if ($stock > 0) {
            app(InventoryCostingService::class)->addOwnedStock(
                $product, $stock, 2, 'NIS', 'opening_stock', $product->id, userId: $user->id);
        }
        app(SalesDailySessionService::class)->openSession($user,
            confirmOpeningVariance: true, sessionType: SalesDailySessionService::TYPE_SALES_ORDERS);
        $service = app(SalesOrderService::class);
        $order = $service->store($user, new Request([
            'customer_name' => 'Delivery stock test',
            'payment_type' => 'credit',
            'reserve_stock' => true,
            'acknowledge_negative_stock' => true,
            'items' => [['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 5]],
        ]));
        $confirmed = $service->confirm($user, $order->id);
        $deductedAt = $confirmed->stock_deducted_at->toDateTimeString();
        $itemId = $confirmed->items->sole()->id;
        $this->assertSame($stock - 2, (int) $product->fresh()->stock);

        $movements = ProductStockMovement::where('product_id', $product->id)->orderBy('id')->get()->toArray();
        $allocations = InventoryCostAllocation::where('product_id', $product->id)->orderBy('id')->get()->toArray();
        $this->assertCount($stock > 0 ? 2 : 1, $movements);

        foreach (['items_group', 'packaged', 'testing', 'document'] as $category) {
            SalesOrderMedia::create([
                'sales_order_id' => $order->id, 'status_at_upload' => 'confirmed',
                'category' => $category, 'type' => 'image', 'path' => 'tests/delivery.jpg', 'uploaded_by' => $user->id,
            ]);
        }
        $service->markReady($user, $order->id);

        foreach ([
            ['customer_phone' => '0599123456'],
            ['customer_address' => 'عنوان التوصيل'],
            ['customer_delivery_fee' => 5],
        ] as $payload) {
            $updated = $service->update($user, $order->id, new Request($payload));
            $this->assertSame('ready', $updated->status);
            $this->assertSame($deductedAt, $updated->stock_deducted_at->toDateTimeString());
            $this->assertSame($itemId, $updated->items->sole()->id);
            $this->assertSame(2, (int) $updated->items->sole()->dispatched_qty);
        }

        $carrier = DeliveryCompany::create([
            'name' => 'Stock test pickup', 'code' => 'stock_test_pickup',
            'delivery_type' => 'pickup', 'is_active' => true,
        ]);
        $handedOver = app(SalesOrderFulfillmentService::class)->handoverToDelivery(
            $user, $order->id, ['delivery_company_id' => $carrier->id]);

        $this->assertSame('with_delivery', $handedOver->status);
        $this->assertSame('0599123456', $handedOver->customer_phone);
        $this->assertSame('عنوان التوصيل', $handedOver->customer_address);
        $this->assertSame(15.0, $handedOver->total);
        $this->assertSame($stock - 2, (int) $product->fresh()->stock);
        $this->assertSame($movements,
            ProductStockMovement::where('product_id', $product->id)->orderBy('id')->get()->toArray());
        $this->assertSame($allocations,
            InventoryCostAllocation::where('product_id', $product->id)->orderBy('id')->get()->toArray());
    }

    public function test_draft_delivery_details_preserve_an_existing_reservation_when_stock_has_run_out(): void
    {
        $user = User::factory()->create(['type' => 'admin']);
        $product = $this->product(2);
        $service = app(SalesOrderService::class);
        $order = $service->store($user, new Request([
            'reserve_stock' => true,
            'items' => [['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 5]],
        ]));
        $product->update(['stock' => -139]);

        $updated = $service->update($user, $order->id, new Request(['customer_address' => 'عنوان جديد']));

        $this->assertSame('عنوان جديد', $updated->customer_address);
        $this->assertSame(2, (int) $updated->items->sole()->reserved_qty);
        $this->assertSame(-139, (int) $product->fresh()->stock);
        $this->assertSame(0, ProductStockMovement::where('product_id', $product->id)->count());
    }

    public function test_enabling_stock_reservation_still_requires_acknowledging_a_shortage(): void
    {
        $user = User::factory()->create(['type' => 'admin']);
        $product = $this->product(-139);
        $service = app(SalesOrderService::class);
        $order = $service->store($user, new Request([
            'reserve_stock' => false,
            'items' => [['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 5]],
        ]));

        try {
            $service->update($user, $order->id, new Request(['reserve_stock' => true]));
            $this->fail('A new stock reservation must still check for shortages.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('acknowledge_negative_stock', $exception->errors());
        }
        $this->assertFalse($order->fresh()->reserves_stock);

        $updated = $service->update($user, $order->id, new Request([
            'reserve_stock' => true, 'acknowledge_negative_stock' => true,
        ]));
        $this->assertTrue($updated->reserves_stock);
        $this->assertSame(2, (int) $updated->items->sole()->reserved_qty);
        $this->assertSame(-139, (int) $product->fresh()->stock);
    }

    private function product(int $stock): Product
    {
        $id = (int) (Product::withTrashed()->max('id') ?? 0) + 1;

        return Product::withoutEvents(fn () => Product::create([
            'id' => $id, 'product_code' => (string) $id,
            'nameAr' => 'سلك بريك داخلي', 'nameEng' => 'Brake cable',
            'stock' => $stock, 'normailPrice' => 5, 'wholesalePrice' => 2,
        ]));
    }
}
