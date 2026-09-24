<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Models\ProductStockMovement;
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
}
