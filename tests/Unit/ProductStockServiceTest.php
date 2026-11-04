<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Models\ProductStockMovement;
use App\Services\InventoryCostingService;
use App\Services\ProductStockService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProductStockServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');

        Schema::create('products', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->string('product_code')->nullable();
            $table->string('nameAr')->nullable();
            $table->integer('stock')->default(0);
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('product_stock_movements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('size_id')->nullable();
            $table->unsignedBigInteger('size_color_id')->nullable();
            $table->string('type');
            $table->integer('quantity');
            $table->integer('stock_before');
            $table->integer('stock_after');
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('sizes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('itemId');
            $table->string('size')->nullable();
            $table->timestamps();
        });
        Schema::create('size_colors', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sizeId');
            $table->integer('stock')->default(0);
            $table->timestamps();
        });
        Schema::create('closeouts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->string('status')->default('ongoing');
            $table->timestamps();
        });
        Schema::create('app_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });
        Schema::create('inventory_cost_layers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('size_id')->nullable();
            $table->unsignedBigInteger('size_color_id')->nullable();
            $table->decimal('quantity', 14, 4);
            $table->decimal('remaining_quantity', 14, 4);
            $table->decimal('unit_cost', 18, 6);
            $table->string('currency')->default('شيكل');
            $table->string('source_type');
            $table->unsignedBigInteger('source_id')->nullable();
            $table->timestamp('effective_at')->nullable();
            $table->timestamps();
        });
        Schema::create('inventory_cost_allocations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('inventory_cost_layer_id')->nullable();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('size_id')->nullable();
            $table->unsignedBigInteger('size_color_id')->nullable();
            $table->decimal('quantity', 14, 4);
            $table->decimal('unit_cost', 18, 6);
            $table->decimal('total_cost', 18, 6);
            $table->string('method');
            $table->string('reference_type');
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->timestamps();
        });
        Schema::create('inventory_cost_balances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('size_id')->nullable();
            $table->unsignedBigInteger('size_color_id')->nullable();
            $table->string('identity_key')->unique();
            $table->decimal('quantity', 14, 4)->default(0);
            $table->decimal('inventory_value', 18, 6)->default(0);
            $table->decimal('moving_average_unit_cost', 18, 6)->default(0);
            $table->string('currency')->default('شيكل');
            $table->boolean('needs_review')->default(false);
            $table->text('review_reason')->nullable();
            $table->timestamps();
        });
    }

    public function test_adjust_stock_can_reverse_an_effect_for_a_soft_deleted_product(): void
    {
        $product = Product::withoutEvents(fn () => Product::query()->create([
            'id' => 344,
            'product_code' => '344',
            'nameAr' => 'Deleted product',
            'stock' => 2,
        ]));
        Product::withoutEvents(fn () => $product->delete());

        Product::withoutEvents(fn () => app(ProductStockService::class)->adjustStock(
            product: $product,
            quantityDelta: 3,
            type: ProductStockMovement::TYPE_SALE_CANCEL,
            referenceType: 'sales_order_purge',
            referenceId: 77,
            note: 'Reverse test order stock effect',
        ));

        $deletedProduct = Product::withTrashed()->findOrFail($product->id);
        $this->assertTrue($deletedProduct->trashed());
        $this->assertSame(5, (int) $deletedProduct->stock);
        $this->assertDatabaseHas('product_stock_movements', [
            'product_id' => 344,
            'type' => ProductStockMovement::TYPE_SALE_CANCEL,
            'quantity' => 3,
            'stock_before' => 2,
            'stock_after' => 5,
            'reference_type' => 'sales_order_purge',
            'reference_id' => 77,
        ]);
    }

    public function test_zero_stock_colors_keep_the_uncolored_inventory_currency(): void
    {
        $product = $this->productWithEmptyColors();
        DB::table('inventory_cost_layers')->insert([
            'product_id' => $product->id,
            'quantity' => 188,
            'remaining_quantity' => 188,
            'unit_cost' => 5,
            'currency' => 'USD',
            'source_type' => 'opening_stock_backfill',
            'effective_at' => now(),
        ]);
        DB::table('inventory_cost_balances')->insert([
            'product_id' => $product->id,
            'identity_key' => 'product:178:variant:main',
            'quantity' => 188,
            'inventory_value' => 940,
            'currency' => 'دولار',
        ]);

        $costing = app(InventoryCostingService::class);
        $summary = $costing->productSummary($product);

        $this->assertSame('دولار', $summary['currency']);
        $this->assertSame(0.0, (float) $summary['quantity_on_hand']);
        $costing->assertCurrencyCompatible($product, $summary['currency']);
        $this->assertSame(188.0, (float) DB::table('inventory_cost_layers')->sum('remaining_quantity'));
    }

    public function test_empty_colors_without_inventory_default_to_shekels(): void
    {
        $summary = app(InventoryCostingService::class)->productSummary($this->productWithEmptyColors());

        $this->assertSame('شيكل', $summary['currency']);
        $this->assertTrue($summary['cost_coverage_complete']);
    }

    public function test_empty_colors_do_not_hide_genuinely_incompatible_inventory_currencies(): void
    {
        $product = $this->productWithEmptyColors();
        foreach (['ILS', 'USD'] as $currency) {
            DB::table('inventory_cost_layers')->insert([
                'product_id' => $product->id,
                'quantity' => 1,
                'remaining_quantity' => 1,
                'unit_cost' => 5,
                'currency' => $currency,
                'source_type' => 'opening_stock_backfill',
                'effective_at' => now(),
            ]);
        }
        $costing = app(InventoryCostingService::class);
        $summary = $costing->productSummary($product);

        $this->assertSame('mixed', $summary['currency']);
        $this->assertFalse($summary['cost_coverage_complete']);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $costing->assertCurrencyCompatible($product, $summary['currency']);
    }

    public function test_stock_adjustment_response_shows_the_specific_validation_error(): void
    {
        $this->productWithEmptyColors();
        $request = \Illuminate\Http\Request::create('/api/product/stock/adjust', 'POST', [
            'product_id' => 178,
            'actual_quantity' => -1,
            'reason' => 'جرد فعلي',
        ]);

        $response = app(\App\Http\Controllers\API\ProductStockController::class)->adjust($request);
        $body = $response->getData(true);

        $this->assertSame('error', $body['status']);
        $this->assertSame($body['errors']['actual_quantity'][0], $body['message']);
        $this->assertNotSame(__('messages.validation_failed'), $body['message']);
    }

    private function productWithEmptyColors(): Product
    {
        $product = Product::withoutEvents(fn () => Product::query()->create([
            'id' => 178,
            'product_code' => '000180',
            'nameAr' => 'علاقة جوال المنيوم رخيص',
            'stock' => 0,
        ]));
        DB::table('sizes')->insert(['id' => 329, 'itemId' => 178, 'size' => '1']);
        foreach ([986, 987, 988] as $id) {
            DB::table('size_colors')->insert(['id' => $id, 'sizeId' => 329, 'stock' => 0]);
        }

        return $product->fresh(['sizes.colorSizes']);
    }

    public function test_explicit_destructive_reversal_can_leave_negative_stock_with_audited_movement(): void
    {
        $product = Product::withoutEvents(fn () => Product::query()->create([
            'id' => 355,
            'product_code' => '355',
            'nameAr' => 'Purchased product already consumed',
            'stock' => 2,
        ]));

        Product::withoutEvents(fn () => app(ProductStockService::class)->adjustStock(
            product: $product,
            quantityDelta: -5,
            type: ProductStockMovement::TYPE_PURCHASE_INVOICE_DELETE,
            referenceType: 'purchase_invoice_delete',
            referenceId: 91,
            note: 'Reverse deleted purchase invoice',
            allowNegative: true,
        ));

        $this->assertSame(-3, (int) $product->fresh()->stock);
        $this->assertDatabaseHas('product_stock_movements', [
            'product_id' => 355,
            'type' => ProductStockMovement::TYPE_PURCHASE_INVOICE_DELETE,
            'quantity' => -5,
            'stock_before' => 2,
            'stock_after' => -3,
            'reference_type' => 'purchase_invoice_delete',
            'reference_id' => 91,
        ]);
    }

    public function test_positive_restoration_can_improve_stock_that_remains_negative(): void
    {
        $product = Product::withoutEvents(fn () => Product::query()->create([
            'id' => 356,
            'product_code' => '356',
            'nameAr' => 'Negative stock sale cancellation',
            'stock' => -10,
        ]));

        Product::withoutEvents(fn () => app(ProductStockService::class)->adjustStock(
            product: $product,
            quantityDelta: 5,
            type: ProductStockMovement::TYPE_SALE_CANCEL,
            referenceType: 'instant_sale',
            referenceId: 92,
            note: 'Restore cancelled negative-stock sale',
        ));

        $this->assertSame(-5, (int) $product->fresh()->stock);
        $this->assertDatabaseHas('product_stock_movements', [
            'product_id' => 356,
            'type' => ProductStockMovement::TYPE_SALE_CANCEL,
            'quantity' => 5,
            'stock_before' => -10,
            'stock_after' => -5,
            'reference_type' => 'instant_sale',
            'reference_id' => 92,
        ]);
    }

    public function test_purchase_purge_restores_return_allocation_then_consumes_the_purchase_layer(): void
    {
        DB::table('app_settings')->insert([
            'key' => 'inventory_costing_method',
            'value' => 'fifo',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $product = Product::withoutEvents(fn () => Product::query()->create([
            'id' => 366,
            'product_code' => '366',
            'nameAr' => 'Returned purchase product',
            'stock' => 7,
        ]));
        DB::table('inventory_cost_layers')->insert([
            'id' => 1,
            'product_id' => $product->id,
            'quantity' => 10,
            'remaining_quantity' => 7,
            'unit_cost' => 10,
            'currency' => 'شيكل',
            'source_type' => 'purchase_receipt_item',
            'source_id' => 15,
            'effective_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('inventory_cost_balances')->insert([
            'product_id' => $product->id,
            'identity_key' => 'product:366:variant:main',
            'quantity' => 7,
            'inventory_value' => 70,
            'moving_average_unit_cost' => 10,
            'currency' => 'شيكل',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('inventory_cost_allocations')->insert([
            'inventory_cost_layer_id' => 1,
            'product_id' => $product->id,
            'quantity' => 3,
            'unit_cost' => 10,
            'total_cost' => 30,
            'method' => 'fifo',
            'reference_type' => 'purchase_return',
            'reference_id' => 50,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $costing = app(InventoryCostingService::class);
        Product::withoutEvents(fn () => $costing->reverseOwnedStockConsumption(
            product: $product,
            quantity: 3,
            originalReferenceType: 'purchase_return',
            originalReferenceId: 50,
            movementType: ProductStockMovement::TYPE_PURCHASE_INVOICE_DELETE_RETURN_RESTORE,
            reversalReferenceType: 'purchase_invoice_delete',
            reversalReferenceId: 91,
        ));

        $this->assertSame(10, (int) $product->fresh()->stock);
        $this->assertSame(10.0, (float) DB::table('inventory_cost_layers')->where('id', 1)->value('remaining_quantity'));
        $this->assertDatabaseMissing('inventory_cost_allocations', ['reference_type' => 'purchase_return', 'reference_id' => 50]);

        Product::withoutEvents(fn () => $costing->consumeOwnedStock(
            product: $product->fresh(),
            quantity: 10,
            movementType: ProductStockMovement::TYPE_PURCHASE_INVOICE_DELETE,
            referenceType: 'purchase_invoice_delete',
            referenceId: 91,
            allowNegative: true,
            preferredLayerIds: [1],
        ));

        $this->assertSame(0, (int) $product->fresh()->stock);
        $this->assertSame(0.0, (float) DB::table('inventory_cost_layers')->where('id', 1)->value('remaining_quantity'));
        $this->assertSame(0.0, (float) DB::table('inventory_cost_balances')->where('product_id', 366)->value('inventory_value'));
    }
}
