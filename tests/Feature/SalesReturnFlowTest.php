<?php

namespace Tests\Feature;

use App\Models\Box;
use App\Models\Customer;
use App\Models\DebtTransaction;
use App\Models\InstantSale;
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
