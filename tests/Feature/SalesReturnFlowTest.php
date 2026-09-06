<?php

namespace Tests\Feature;

use App\Models\Box;
use App\Models\Customer;
use App\Models\DebtTransaction;
use App\Models\InstantSale;
use App\Models\InventoryCostLayer;
use App\Models\Product;
use App\Models\ProductStockMovement;
use App\Models\SalesDailySession;
use App\Models\User;
use App\Services\SalesReturnService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SalesReturnFlowTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['type' => 'admin']);
    }

    public function test_credit_return_restores_stock_and_creates_customer_credit(): void
    {
        [$customer, $product, $sale] = $this->instantSale(quantity: 3, unitPrice: 40, stock: 7);

        $return = app(SalesReturnService::class)->create($this->user, [
            'person_type' => 'customer',
            'person_id' => $customer->id,
            'cash_refund_amount' => 0,
            'items' => [[
                'source_type' => 'instant_sale',
                'source_item_id' => $sale->id,
                'quantity' => 2,
                'unit_price' => 40,
            ]],
        ]);

        $this->assertSame(9, (int) $product->fresh()->stock);
        $this->assertEquals(80, (float) $return->total_amount);
        $this->assertEquals(80, (float) $return->credit_amount);
        $this->assertDatabaseHas('debt_transactions', [
            'customer_id' => $customer->id,
            'type' => 'taken',
            'source' => 'sales_return',
            'source_id' => $return->id,
            'amount' => 80,
        ]);
        $this->assertDatabaseHas('product_stock_movements', [
            'product_id' => $product->id,
            'type' => ProductStockMovement::TYPE_SALES_RETURN,
            'quantity' => 2,
            'reference_type' => 'sales_return',
            'reference_id' => $return->id,
        ]);
        $returnItem = $return->items()->firstOrFail();
        $this->assertDatabaseHas('inventory_cost_layers', [
            'product_id' => $product->id,
            'source_type' => 'sales_return_item',
            'source_id' => $returnItem->id,
            'quantity' => 2,
            'remaining_quantity' => 2,
        ]);
        $this->assertSame(
            (float) $returnItem->inventory_unit_cost,
            (float) InventoryCostLayer::where('source_type', 'sales_return_item')
                ->where('source_id', $returnItem->id)
                ->value('unit_cost')
        );
    }

    public function test_return_cannot_exceed_quantity_remaining_after_previous_return(): void
    {
        [$customer, , $sale] = $this->instantSale(quantity: 2, unitPrice: 25, stock: 5);
        $payload = [
            'person_type' => 'customer',
            'person_id' => $customer->id,
            'cash_refund_amount' => 0,
            'items' => [[
                'source_type' => 'instant_sale',
                'source_item_id' => $sale->id,
                'quantity' => 2,
                'unit_price' => 25,
            ]],
        ];
        app(SalesReturnService::class)->create($this->user, $payload);

        $this->expectException(ValidationException::class);
        app(SalesReturnService::class)->create($this->user, $payload);
    }

    public function test_cancelling_return_reverses_stock_credit_and_cost_layer(): void
    {
        [$customer, $product, $sale] = $this->instantSale(quantity: 2, unitPrice: 30, stock: 5);
        $service = app(SalesReturnService::class);
        $return = $service->create($this->user, [
            'person_type' => 'customer',
            'person_id' => $customer->id,
            'cash_refund_amount' => 0,
            'items' => [[
                'source_type' => 'instant_sale',
                'source_item_id' => $sale->id,
                'quantity' => 1,
                'unit_price' => 30,
            ]],
        ]);

        $this->assertSame(6, (int) $product->fresh()->stock);
        $cancelled = $service->cancel($this->user, $return->id, 'خطأ في كمية المرتجع');

        $this->assertSame('cancelled', $cancelled->status);
        $this->assertSame(5, (int) $product->fresh()->stock);
        $this->assertSame(0, DebtTransaction::query()
            ->active()
            ->where('source', 'sales_return')
            ->where('source_id', $return->id)
            ->count());
        $returnItem = $return->items()->firstOrFail();
        $this->assertEquals(0, (float) InventoryCostLayer::query()
            ->where('source_type', 'sales_return_item')
            ->where('source_id', $returnItem->id)
            ->value('remaining_quantity'));
    }

    public function test_sale_with_active_return_cannot_be_cancelled_or_edited(): void
    {
        [$customer, , $sale] = $this->instantSale(quantity: 2, unitPrice: 30, stock: 5);
        $service = app(SalesReturnService::class);
        $service->create($this->user, [
            'person_type' => 'customer',
            'person_id' => $customer->id,
            'cash_refund_amount' => 0,
            'items' => [[
                'source_type' => 'instant_sale',
                'source_item_id' => $sale->id,
                'quantity' => 1,
                'unit_price' => 30,
            ]],
        ]);

        $this->expectException(ValidationException::class);
        $service->assertInstantSaleHasNoActiveDirectReturns($sale);
    }

    public function test_mixed_cash_and_credit_return_uses_open_daily_box(): void
    {
        [$customer, , $sale] = $this->instantSale(quantity: 2, unitPrice: 50, stock: 4);
        SalesDailySession::create([
            'user_id' => $this->user->id,
            'session_type' => 'instant_sales',
            'business_date' => now()->toDateString(),
            'status' => 'open',
            'opened_at' => now(),
            'opened_by_user_id' => $this->user->id,
        ]);
        $box = Box::create([
            'name' => 'صندوق اختبار المرتجعات',
            'type' => config('sales_daily.box_type'),
            'user_id' => $this->user->id,
            'currency' => 'شيكل',
            'total' => 200,
            'is_shown' => true,
        ]);

        $return = app(SalesReturnService::class)->create($this->user, [
            'person_type' => 'customer',
            'person_id' => $customer->id,
            'cash_refund_amount' => 30,
            'refund_box_id' => $box->id,
            'items' => [[
                'source_type' => 'instant_sale',
                'source_item_id' => $sale->id,
                'quantity' => 2,
                'unit_price' => 50,
            ]],
        ]);

        $this->assertEquals(170, (float) $box->fresh()->total);
        $this->assertEquals(30, (float) $return->cash_refund_amount);
        $this->assertEquals(70, (float) $return->credit_amount);
        $this->assertSame(1, DebtTransaction::where('source', 'sales_return')->where('source_id', $return->id)->count());
    }

    public function test_cash_return_from_closed_session_requires_current_open_box_before_cancellation(): void
    {
        [$customer, $product, $sale] = $this->instantSale(quantity: 1, unitPrice: 30, stock: 4);
        $originalSession = SalesDailySession::create([
            'user_id' => $this->user->id,
            'session_type' => 'instant_sales',
            'business_date' => now()->subDay()->toDateString(),
            'status' => 'open',
            'opened_at' => now()->subDay(),
            'opened_by_user_id' => $this->user->id,
        ]);
        $box = Box::create([
            'name' => 'صندوق إلغاء مرتجع نقدي',
            'type' => config('sales_daily.box_type'),
            'user_id' => $this->user->id,
            'currency' => 'شيكل',
            'total' => 200,
            'is_shown' => true,
        ]);
        $service = app(SalesReturnService::class);
        $return = $service->create($this->user, [
            'person_type' => 'customer',
            'person_id' => $customer->id,
            'cash_refund_amount' => 30,
            'refund_box_id' => $box->id,
            'items' => [[
                'source_type' => 'instant_sale',
                'source_item_id' => $sale->id,
                'quantity' => 1,
                'unit_price' => 30,
            ]],
        ]);
        $originalSession->update(['status' => 'closed', 'closed_at' => now()]);

        $blocked = $service->show($return->id, $this->user)['cancellation_preview'];
        $this->assertFalse($blocked['can_cancel']);
        $this->assertSame('original_box_closed', $blocked['scenario']);

        SalesDailySession::create([
            'user_id' => $this->user->id,
            'session_type' => 'instant_sales',
            'business_date' => now()->toDateString(),
            'status' => 'open',
            'opened_at' => now(),
            'opened_by_user_id' => $this->user->id,
        ]);

        $ready = $service->show($return->id, $this->user)['cancellation_preview'];
        $this->assertTrue($ready['can_cancel']);
        $this->assertSame('original_box_closed', $ready['scenario']);
        $service->cancel($this->user, $return->id, 'استرجاع النقد من الزبون اليوم');

        $this->assertSame(4, (int) $product->fresh()->stock);
        $this->assertEquals(200, (float) $box->fresh()->total);
    }

    public function test_cash_return_cancellation_is_blocked_while_closing_is_under_review(): void
    {
        [$customer, , $sale] = $this->instantSale(quantity: 1, unitPrice: 25, stock: 3);
        $session = SalesDailySession::create([
            'user_id' => $this->user->id,
            'session_type' => 'instant_sales',
            'business_date' => now()->toDateString(),
            'status' => 'open',
            'opened_at' => now(),
            'opened_by_user_id' => $this->user->id,
        ]);
        $box = Box::create([
            'name' => 'صندوق قيد الإغلاق',
            'type' => config('sales_daily.box_type'),
            'user_id' => $this->user->id,
            'currency' => 'شيكل',
            'total' => 100,
            'is_shown' => true,
        ]);
        $service = app(SalesReturnService::class);
        $return = $service->create($this->user, [
            'person_type' => 'customer',
            'person_id' => $customer->id,
            'cash_refund_amount' => 25,
            'refund_box_id' => $box->id,
            'items' => [[
                'source_type' => 'instant_sale',
                'source_item_id' => $sale->id,
                'quantity' => 1,
                'unit_price' => 25,
            ]],
        ]);
        $session->update(['status' => 'closing_requested']);

        $preview = $service->show($return->id, $this->user)['cancellation_preview'];
        $this->assertFalse($preview['can_cancel']);
        $this->assertSame('closing_requested', $preview['scenario']);

        $this->expectException(ValidationException::class);
        $service->cancel($this->user, $return->id, 'محاولة أثناء مراجعة الإغلاق');
    }

    /** @return array{Customer, Product, InstantSale} */
    private function instantSale(int $quantity, float $unitPrice, int $stock): array
    {
        $customer = Customer::create(['name' => 'زبون مرتجع '.uniqid(), 'phone' => '059'.random_int(1000000, 9999999), 'is_canceled' => false]);
        $productId = (int) Product::withTrashed()->max('id') + random_int(100, 999);
        $product = Product::withoutEvents(fn () => Product::create([
            'id' => $productId,
            'product_code' => (string) $productId,
            'nameAr' => 'منتج مرتجع '.$productId,
            'nameEng' => 'Return product '.$productId,
            'stock' => $stock,
            'normailPrice' => $unitPrice,
            'wholesalePrice' => $unitPrice,
        ]));
        $sale = InstantSale::create([
            'product_id' => $product->id,
            'quantity' => $quantity,
            'cost' => $unitPrice,
            'discount' => 0,
            'total_cost' => $quantity * $unitPrice,
            'type' => 'normal',
            'sale_kind' => 'regular',
            'buyer_type' => 'customer',
            'buyer_id' => $customer->id,
            'buyer_name' => $customer->name,
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        return [$customer, $product, $sale];
    }
}
