<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\Box;
use App\Models\DebtTransaction;
use App\Models\InventoryCostLayer;
use App\Models\Product;
use App\Models\PurchaseAmanatStock;
use App\Models\PurchasePayment;
use App\Models\PurchasePriceHistory;
use App\Models\ReturnModel;
use App\Models\Seller;
use App\Models\User;
use App\Services\InventoryCostingService;
use App\Services\PurchaseAccountService;
use App\Services\PurchasingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PurchasingInventoryV2Test extends TestCase
{
    use DatabaseTransactions;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['type' => 'admin']);
    }

    public function test_purchase_creation_does_not_increase_stock_and_receiving_creates_cost_layer(): void
    {
        $seller = Seller::create(['name' => 'Supplier', 'phone' => '0591']);
        $product = $this->product(1001, 50);

        $bill = app(PurchasingService::class)->createPurchase([
            'seller_id' => $seller->id,
            'products' => [
                ['product_id' => $product->id, 'quantity' => 100, 'purchase_price' => 3],
            ],
        ], $this->user->id);

        $this->assertSame(50, (int) $product->fresh()->stock);

        app(PurchasingService::class)->receive($bill, [
            'items' => [
                ['bill_item_id' => $bill->items()->first()->id, 'accepted_quantity' => 100, 'unit_price' => 3],
            ],
        ], $this->user->id);

        $this->assertSame(150, (int) $product->fresh()->stock);
        $this->assertDatabaseHas('inventory_cost_layers', [
            'product_id' => $product->id,
            'quantity' => 100,
            'remaining_quantity' => 100,
            'unit_cost' => 3,
        ]);
    }

    public function test_extra_quantity_becomes_amanat_then_can_be_purchased_at_negotiated_price(): void
    {
        $seller = Seller::create(['name' => 'Supplier', 'phone' => '0592']);
        $product = $this->product(1002, 0);

        $bill = app(PurchasingService::class)->createPurchase([
            'seller_id' => $seller->id,
            'products' => [
                ['product_id' => $product->id, 'quantity' => 10, 'purchase_price' => 5],
            ],
        ], $this->user->id);

        $item = $bill->items()->first();
        app(PurchasingService::class)->receive($bill, [
            'items' => [
                ['bill_item_id' => $item->id, 'accepted_quantity' => 10, 'extra_quantity' => 2, 'unit_price' => 5],
            ],
        ], $this->user->id);

        $this->assertSame(10, (int) $product->fresh()->stock);
        $amanat = PurchaseAmanatStock::firstOrFail();
        $this->assertEquals(2, (float) $amanat->remaining_quantity);

        app(PurchasingService::class)->purchaseAmanat($amanat, 2, 3, $this->user->id);

        $this->assertSame(12, (int) $product->fresh()->stock);
        $this->assertDatabaseHas('purchase_price_histories', [
            'product_id' => $product->id,
            'unit_price' => 3,
            'manual_override' => 1,
        ]);
        $this->assertDatabaseHas('inventory_cost_layers', [
            'product_id' => $product->id,
            'quantity' => 2,
            'unit_cost' => 3,
        ]);
    }

    public function test_amanat_can_be_returned_without_becoming_owned_stock(): void
    {
        $seller = Seller::create(['name' => 'Supplier', 'phone' => '05922']);
        $product = $this->product(1012, 0);

        $bill = app(PurchasingService::class)->createPurchase([
            'seller_id' => $seller->id,
            'products' => [
                ['product_id' => $product->id, 'quantity' => 10, 'purchase_price' => 5],
            ],
        ], $this->user->id);

        app(PurchasingService::class)->receive($bill, [
            'items' => [
                ['bill_item_id' => $bill->items()->first()->id, 'accepted_quantity' => 10, 'extra_quantity' => 2, 'unit_price' => 5],
            ],
        ], $this->user->id);

        $amanat = PurchaseAmanatStock::where('bill_id', $bill->id)->firstOrFail();
        app(PurchaseAccountService::class)->returnAmanat($amanat, 2, 'رجعت للمورد', $this->user->id);

        $this->assertSame(10, (int) $product->fresh()->stock);
        $this->assertEquals(0, (float) $amanat->fresh()->remaining_quantity);
        $this->assertSame('returned', $amanat->fresh()->status);
    }

    public function test_purchase_finalization_and_payments_update_invoice_ledger_and_boxes(): void
    {
        $seller = Seller::create(['name' => 'Supplier', 'phone' => '0593']);
        $product = $this->product(1003, 0);
        $boxA = Box::create(['name' => 'A', 'total' => 20000, 'currency' => 'شيكل']);
        $boxB = Box::create(['name' => 'B', 'total' => 10000, 'currency' => 'شيكل']);

        $bill = app(PurchasingService::class)->createPurchase([
            'seller_id' => $seller->id,
            'products' => [
                ['product_id' => $product->id, 'quantity' => 1, 'purchase_price' => 10000],
            ],
        ], $this->user->id);

        app(PurchasingService::class)->receive($bill, [
            'items' => [
                ['bill_item_id' => $bill->items()->first()->id, 'accepted_quantity' => 1, 'unit_price' => 10000],
            ],
        ], $this->user->id);

        app(PurchasingService::class)->finalize($bill, 5000, $boxA->id, $this->user->id);
        $this->assertEquals(15000, (float) $boxA->fresh()->total);
        $this->assertEquals(5000, (float) Bill::find($bill->id)->paid_amount);

        app(PurchasingService::class)->recordPayment($bill->fresh(), 1000, $boxB->id, 'payment', null, $this->user->id);
        app(PurchasingService::class)->recordPayment($bill->fresh(), 2000, $boxA->id, 'payment', null, $this->user->id);
        app(PurchasingService::class)->recordPayment($bill->fresh(), 2000, $boxA->id, 'payment', null, $this->user->id);

        $bill = $bill->fresh();
        $this->assertEquals(10000, (float) $bill->final_total);
        $this->assertEquals(10000, (float) $bill->paid_amount);
        $this->assertSame('paid', $bill->payment_status);
        $this->assertEquals(11000, (float) $boxA->fresh()->total);
        $this->assertEquals(9000, (float) $boxB->fresh()->total);
        $this->assertSame(5, DebtTransaction::where('seller_id', $seller->id)->count());
        $this->assertSame(4, PurchasePayment::where('bill_id', $bill->id)->count());
    }

    public function test_supplier_account_payment_can_allocate_oldest_finalized_invoices(): void
    {
        $seller = Seller::create(['name' => 'Supplier', 'phone' => '05933']);
        $product = $this->product(1013, 0);
        $box = Box::create(['name' => 'A', 'total' => 20000, 'currency' => 'شيكل']);

        $bill = app(PurchasingService::class)->createPurchase([
            'seller_id' => $seller->id,
            'products' => [
                ['product_id' => $product->id, 'quantity' => 1, 'purchase_price' => 1000],
            ],
        ], $this->user->id);
        app(PurchasingService::class)->receive($bill, [
            'items' => [
                ['bill_item_id' => $bill->items()->first()->id, 'accepted_quantity' => 1, 'unit_price' => 1000],
            ],
        ], $this->user->id);
        app(PurchasingService::class)->finalize($bill, 0, null, $this->user->id);

        app(PurchaseAccountService::class)->paySupplierOnAccount([
            'seller_id' => $seller->id,
            'amount' => 600,
            'box_id' => $box->id,
            'currency' => 'شيكل',
            'allocate_oldest_first' => true,
        ], $this->user->id);

        $this->assertEquals(19400, (float) $box->fresh()->total);
        $this->assertEquals(600, (float) $bill->fresh()->paid_amount);
        $this->assertSame('partially_paid', $bill->fresh()->payment_status);
        $this->assertDatabaseHas('purchase_payments', [
            'seller_id' => $seller->id,
            'bill_id' => null,
            'type' => 'account_payment',
            'amount' => 600,
        ]);
    }

    public function test_purchase_return_updates_stock_cost_layers_ledger_and_cash_refund_box(): void
    {
        $seller = Seller::create(['name' => 'Supplier', 'phone' => '05944']);
        $product = $this->product(1014, 0);
        $box = Box::create(['name' => 'Refund Box', 'total' => 1000, 'currency' => 'شيكل']);

        $bill = app(PurchasingService::class)->createPurchase([
            'seller_id' => $seller->id,
            'products' => [
                ['product_id' => $product->id, 'quantity' => 10, 'purchase_price' => 5],
            ],
        ], $this->user->id);
        app(PurchasingService::class)->receive($bill, [
            'items' => [
                ['bill_item_id' => $bill->items()->first()->id, 'accepted_quantity' => 10, 'unit_price' => 5],
            ],
        ], $this->user->id);
        app(PurchasingService::class)->finalize($bill, 50, $box->id, $this->user->id);

        $return = app(PurchaseAccountService::class)->createPurchaseReturn([
            'seller_id' => $seller->id,
            'bill_id' => $bill->id,
            'resolution' => 'cash_refund',
            'refund_box_id' => $box->id,
            'products' => [
                ['product_id' => $product->id, 'bill_item_id' => $bill->items()->first()->id, 'quantity' => 2, 'purchase_price' => 5],
            ],
        ], $this->user->id);
        app(PurchaseAccountService::class)->deliverPurchaseReturn($return, $box->id, $this->user->id);

        $this->assertSame(8, (int) $product->fresh()->stock);
        $this->assertEquals(960, (float) $box->fresh()->total);
        $this->assertEquals(8, (float) InventoryCostLayer::where('product_id', $product->id)->firstOrFail()->remaining_quantity);
        $this->assertSame('delivered', ReturnModel::find($return->id)->status);
        $this->assertDatabaseHas('debt_transactions', [
            'seller_id' => $seller->id,
            'source' => 'purchase_refund',
            'source_id' => $return->id,
            'amount' => 10,
        ]);
    }

    public function test_fifo_and_weighted_average_costing(): void
    {
        $product = $this->product(1004, 150);
        InventoryCostLayer::create([
            'product_id' => $product->id,
            'quantity' => 50,
            'remaining_quantity' => 50,
            'unit_cost' => 5,
            'currency' => 'شيكل',
            'source_type' => 'opening',
            'effective_at' => now()->subDay(),
        ]);
        InventoryCostLayer::create([
            'product_id' => $product->id,
            'quantity' => 100,
            'remaining_quantity' => 100,
            'unit_cost' => 3,
            'currency' => 'شيكل',
            'source_type' => 'purchase',
            'effective_at' => now(),
        ]);

        $costing = app(InventoryCostingService::class);
        $fifo = $costing->consumeCost($product, 20, 'test_sale', 1);
        $this->assertEquals(100, round($fifo['total_cost'], 6));

        $fifo = $costing->consumeCost($product, 40, 'test_sale', 2);
        $this->assertEquals(180, round($fifo['total_cost'], 6));

        $product2 = $this->product(1005, 150);
        InventoryCostLayer::create([
            'product_id' => $product2->id,
            'quantity' => 50,
            'remaining_quantity' => 50,
            'unit_cost' => 5,
            'currency' => 'شيكل',
            'source_type' => 'opening',
            'effective_at' => now()->subDay(),
        ]);
        InventoryCostLayer::create([
            'product_id' => $product2->id,
            'quantity' => 100,
            'remaining_quantity' => 100,
            'unit_cost' => 3,
            'currency' => 'شيكل',
            'source_type' => 'purchase',
            'effective_at' => now(),
        ]);
        $costing->setMethod(InventoryCostingService::METHOD_MOVING_AVERAGE);

        $weighted = $costing->consumeCost($product2, 20, 'test_sale', 3);
        $this->assertEquals(73.333333, round($weighted['total_cost'], 6));
    }

    private function product(int $id, int $stock): Product
    {
        return Product::withoutEvents(fn () => Product::create([
            'id' => $id,
            'product_code' => (string) $id,
            'nameAr' => 'فحمات '.$id,
            'nameEng' => 'Pads '.$id,
            'stock' => $stock,
            'normailPrice' => 10,
            'wholesalePrice' => 8,
        ]));
    }
}
