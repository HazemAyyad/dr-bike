<?php

namespace Tests\Feature;

use App\Models\Box;
use App\Models\Customer;
use App\Models\DebtTransaction;
use App\Models\DeliveryCompany;
use App\Models\Product;
use App\Models\SalesOrderMedia;
use App\Models\User;
use App\Services\AccountingProjectionService;
use App\Services\InventoryCostingService;
use App\Services\SalesDailySessionService;
use App\Services\SalesOrderFulfillmentService;
use App\Services\SalesOrderNotificationService;
use App\Services\SalesOrdersDailyBoxService;
use App\Services\SalesOrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class SalesOrderAdditionalNotesFlowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->refreshStoreSchema();
        Http::preventStrayRequests();
        $this->app->instance(SalesOrderNotificationService::class,
            Mockery::mock(SalesOrderNotificationService::class)->shouldIgnoreMissing());
    }

    public function test_notes_survive_creation_reopening_partial_updates_editing_and_removal(): void
    {
        $user = User::factory()->create(['type' => 'admin']);
        $id = (int) (Product::withTrashed()->max('id') ?? 0) + 1;
        $product = Product::withoutEvents(fn () => Product::query()->create([
            'id' => $id, 'product_code' => (string) $id,
            'nameAr' => 'Paid notes test', 'nameEng' => 'Paid notes test',
            'stock' => 10, 'normailPrice' => 50, 'wholesalePrice' => 30,
        ]));
        $service = app(SalesOrderService::class);
        $order = $service->store($user, new Request([
            'customer_name' => 'Paid notes test', 'reserve_stock' => false,
            'notes' => 'ملاحظة داخلية مستقلة',
            'discount' => 10, 'customer_delivery_fee' => 20, 'total' => 99999,
            'additional_notes' => [
                ['text' => ' تركيب ', 'amount' => 15.5],
                ['text' => 'ملاحظة مجانية', 'amount' => 0],
            ],
            'items' => [['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 50]],
        ]));

        $reopened = $service->show($order->id);
        $detail = $service->formatDetail($reopened);
        $this->assertSame(125.5, $detail['total']);
        $this->assertSame(15.5, $detail['additional_notes_total']);
        $this->assertSame('تركيب', $detail['additional_notes'][0]['text']);
        $this->assertCount(2, $detail['additional_notes']);
        $this->assertSame('ملاحظة داخلية مستقلة', $detail['notes']);

        $updated = $service->update($user, $order->id, new Request(['customer_address' => 'عنوان جديد']));
        $this->assertSame(125.5, $updated->total);
        $this->assertCount(2, $updated->additional_notes);

        $updated = $service->update($user, $order->id, new Request([
            'additional_notes' => [['text' => 'تركيب وتعديل', 'amount' => 25]],
        ]));
        $this->assertSame(135.0, $updated->total);
        $this->assertSame('تركيب وتعديل', $updated->fresh()->additional_notes[0]['text']);

        $updated = $service->update($user, $order->id, new Request(['additional_notes' => []]));
        $this->assertSame(110.0, $updated->total);
        $this->assertSame([], $updated->fresh()->additional_notes);
        $this->assertSame('ملاحظة داخلية مستقلة', $updated->notes);
    }

    public function test_service_only_order_uses_the_normal_deposit_debt_settlement_and_accounting_flow(): void
    {
        $user = User::factory()->create(['type' => 'admin']);
        $customer = Customer::create(['name' => 'Service customer', 'phone' => '0599123456', 'address' => 'عنوان', 'type' => 'retail']);
        $this->openOrdersDrawer($user);
        $box = Box::findOrFail(app(SalesOrdersDailyBoxService::class)->resolve($user)['id']);
        $opening = (float) $box->total;
        $service = app(SalesOrderService::class);
        $order = $service->store($user, new Request([
            'customer_id' => $customer->id, 'payment_type' => 'mixed', 'payment_amount' => 25,
            'discount' => 10, 'customer_delivery_fee' => 5, 'reserve_stock' => true,
            'additional_notes' => [['text' => 'خدمة تركيب', 'amount' => 100]],
        ]));
        $this->assertSame(95.0, $order->total);
        $this->assertSame(0.0, $order->subtotal);
        $this->assertFalse($order->reserves_stock);
        $this->assertCount(0, $order->items);
        $confirmed = $service->confirm($user, $order->id);
        $this->assertSame('confirmed', $confirmed->status);
        $this->assertNull($confirmed->stock_deducted_at);
        $this->assertEquals($opening + 25, $box->fresh()->total);

        // Use the normal attachment requirements without changing their settings.
        foreach (['items_group', 'packaged', 'testing', 'document'] as $category) {
            SalesOrderMedia::create([
                'sales_order_id' => $order->id, 'status_at_upload' => 'confirmed',
                'category' => $category, 'type' => 'image', 'path' => 'tests/service.jpg', 'uploaded_by' => $user->id,
            ]);
        }
        $service->markReady($user, $order->id);
        $carrier = DeliveryCompany::create(['name' => 'Service pickup', 'code' => 'service_test_pickup', 'delivery_type' => 'pickup', 'is_active' => true]);
        $fulfillment = app(SalesOrderFulfillmentService::class);
        $fulfillment->handoverToDelivery($user, $order->id, ['delivery_company_id' => $carrier->id]);
        $delivered = $fulfillment->markDelivered($user, $order->id);
        $this->assertSame('delivered', $delivered->status);
        $this->assertSame(70.0, $delivered->customer_debt_balance);
        $this->assertEquals($opening + 25, $box->fresh()->total);
        $debt = DebtTransaction::where('source', 'sales_order')->where('source_id', $order->id)->sole();
        $this->assertSame($customer->id, $debt->customer_id);
        $this->assertEquals(70, $debt->amount);

        $journal = app(AccountingProjectionService::class)->syncOrFail($delivered);
        $this->assertNotNull($journal);
        $this->assertEquals($journal->lines->sum('debit'), $journal->lines->sum('credit'));
        $this->assertEquals(95, $journal->lines->sum('credit'));

        $settled = $fulfillment->settleDelivery($user, $order->id, [
            'delivery_settled_amount' => 30, 'source' => 'customer_debt',
            'idempotency_key' => 'service-partial-'.$order->id,
        ]);
        $this->assertSame(40.0, $settled->customer_debt_balance);
        $this->assertEquals(40, $debt->fresh()->amount);
        $this->assertEquals($opening + 55, $box->fresh()->total);
        $fulfillment->settleDelivery($user, $order->id, [
            'delivery_settled_amount' => 40, 'source' => 'customer_debt',
            'idempotency_key' => 'service-full-'.$order->id,
        ]);
        $this->assertSame('archived', $fulfillment->archive($user, $order->id)->status);
        $this->assertEquals($opening + 95, $box->fresh()->total);
    }

    public function test_service_cancellation_reverses_the_deposit_without_stock(): void
    {
        $user = User::factory()->create(['type' => 'admin']);
        $this->openOrdersDrawer($user);
        $box = Box::findOrFail(app(SalesOrdersDailyBoxService::class)->resolve($user)['id']);
        $opening = (float) $box->total;
        $service = app(SalesOrderService::class);
        $order = $service->store($user, new Request([
            'items' => [], 'payment_type' => 'mixed', 'payment_amount' => 20,
            'additional_notes' => [['text' => 'خدمة', 'amount' => 50]],
        ]));
        $service->confirm($user, $order->id);
        $this->assertEquals($opening + 20, $box->fresh()->total);
        $canceled = $service->cancel($user, $order->id);
        $this->assertSame('canceled', $canceled->status);
        $this->assertNull($canceled->stock_deducted_at);
        $this->assertEquals($opening, $box->fresh()->total);
        $this->assertEquals(0, $canceled->settlements()->sum('amount'));
    }

    public function test_empty_orders_and_free_notes_without_products_are_rejected(): void
    {
        $user = User::factory()->create(['type' => 'admin']);
        foreach ([[], ['items' => []], ['additional_notes' => [['text' => 'بلا مبلغ', 'amount' => 0]], 'customer_delivery_fee' => 20]] as $payload) {
            try {
                app(SalesOrderService::class)->store($user, new Request($payload));
                $this->fail('An order must contain a product or a charged service.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('items', $exception->errors());
            }
        }
    }

    public function test_confirmed_product_and_service_conversions_preserve_real_stock_and_totals(): void
    {
        $user = User::factory()->create(['type' => 'admin']);
        $this->openOrdersDrawer($user);
        $id = (int) (Product::withTrashed()->max('id') ?? 0) + 1;
        $product = Product::withoutEvents(fn () => Product::query()->create([
            'id' => $id, 'product_code' => (string) $id,
            'nameAr' => 'Conversion test', 'nameEng' => 'Conversion test',
            'stock' => 0, 'normailPrice' => 50, 'wholesalePrice' => 30,
        ]));
        app(InventoryCostingService::class)->addOwnedStock($product, 10, 30, 'NIS', 'opening_stock', $product->id, userId: $user->id);
        $service = app(SalesOrderService::class);
        $items = [['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 50]];
        $order = $service->store($user, new Request([
            'items' => $items, 'payment_type' => 'credit',
            'additional_notes' => [['text' => 'خدمة', 'amount' => 20]],
        ]));
        $service->confirm($user, $order->id);
        $this->assertEquals(8, $product->fresh()->stock);
        $serviceOnly = $service->update($user, $order->id, new Request(['items' => []]));
        $this->assertSame(20.0, $serviceOnly->total);
        $this->assertSame(0.0, $serviceOnly->subtotal);
        $this->assertNull($serviceOnly->stock_deducted_at);
        $this->assertEquals(10, $product->fresh()->stock);
        $withProducts = $service->update($user, $order->id, new Request(['items' => $items]));
        $this->assertSame(120.0, $withProducts->total);
        $this->assertNotNull($withProducts->stock_deducted_at);
        $this->assertEquals(8, $product->fresh()->stock);
    }

    private function openOrdersDrawer(User $user): void
    {
        app(SalesDailySessionService::class)->openSession($user,
            confirmOpeningVariance: true, sessionType: SalesDailySessionService::TYPE_SALES_ORDERS);
    }
}
