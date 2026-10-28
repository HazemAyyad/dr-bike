<?php

namespace Tests\Unit;

use App\Http\Controllers\API\Bills;
use App\Models\Product;
use App\Services\PurchaseVariantService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PurchaseVariantServiceTest extends TestCase
{
    private string $originalConnection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConnection = (string) config('database.default');
        config()->set('database.connections.purchase_variant_test', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]);
        config()->set('database.default', 'purchase_variant_test');
        DB::purge('purchase_variant_test');
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->integer('stock')->default(0);
            $table->decimal('wholesalePrice')->default(0);
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('sizes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('itemId');
            $table->string('size');
            $table->timestamps();
        });
        Schema::create('size_colors', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sizeId');
            $table->string('colorAr');
            $table->string('colorEn');
            $table->string('colorAbbr');
            $table->decimal('normailPrice');
            $table->decimal('wholesalePrice');
            $table->decimal('discount');
            $table->integer('stock');
            $table->string('meta_catalog_sync_status')->nullable();
            $table->timestamps();
        });
        Schema::create('app_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });
        DB::table('products')->insert(['id' => 1, 'stock' => 8, 'wholesalePrice' => 25]);
        DB::table('products')->insert(['id' => 2, 'stock' => 4]);
    }

    protected function tearDown(): void
    {
        DB::disconnect('purchase_variant_test');
        DB::purge('purchase_variant_test');
        config()->set('database.default', $this->originalConnection);
        parent::tearDown();
    }

    public function test_new_size_and_color_have_real_ids_and_zero_stock(): void
    {
        $variant = app(PurchaseVariantService::class)->create(1, ' XL ', ' أحمر ', 40);
        $this->assertGreaterThan(0, $variant->id);
        $this->assertGreaterThan(0, $variant->size->id);
        $this->assertSame('XL', $variant->size->size);
        $this->assertSame('أحمر', $variant->colorAr);
        $this->assertEquals(0, $variant->fresh()->stock);
        $this->assertEquals(8, Product::find(1)->stock);
        $this->assertEquals(25, $variant->wholesalePrice);
    }

    public function test_api_response_exposes_the_saved_size_and_variant_ids(): void
    {
        $response = app(Bills::class)->createPurchaseVariant(Request::create('/purchase/products/variants', 'POST', [
            'product_id' => 1, 'size' => 'XL', 'color' => 'أحمر', 'retail_price' => 40,
        ]), app(PurchaseVariantService::class));
        $data = $response->getData(true);
        $this->assertSame('success', $data['status']);
        $this->assertGreaterThan(0, $data['size']['id']);
        $this->assertGreaterThan(0, $data['variant']['id']);
        $this->assertEquals($data['size']['id'], $data['variant']['sizeId']);
        $this->assertSame('XL', $data['size']['size']);
        $this->assertSame('أحمر', $data['variant']['colorAr']);
        $this->assertEquals(0, $data['variant']['stock']);
    }

    public function test_new_color_reuses_size_and_preserves_other_colors(): void
    {
        $service = app(PurchaseVariantService::class);
        $old = $service->create(1, 'XL', 'أحمر', 40);
        $old->update(['stock' => 8]);
        $new = $service->create(1, 'xl', 'أزرق', 45);
        $this->assertEquals($old->sizeId, $new->sizeId);
        $this->assertEquals(1, DB::table('sizes')->count());
        $this->assertEquals(2, DB::table('size_colors')->count());
        $this->assertEquals(8, $old->fresh()->stock);
        $this->assertEquals(40, $old->fresh()->normailPrice);
        $this->assertEquals(0, $new->stock);
    }

    public function test_retry_reuses_variant_without_overwriting_stock_or_price(): void
    {
        $service = app(PurchaseVariantService::class);
        $old = $service->create(1, 'XL', 'Blue', 40);
        $old->update(['stock' => 8]);
        $retry = $service->create(1, ' xl ', ' blue ', 99);
        $this->assertEquals($old->id, $retry->id);
        $this->assertEquals(8, $retry->stock);
        $this->assertEquals(40, $retry->normailPrice);
        $this->assertEquals(1, DB::table('size_colors')->count());
    }

    public function test_size_and_color_belong_to_requested_product(): void
    {
        $service = app(PurchaseVariantService::class);
        $first = $service->create(1, 'XL', 'أحمر', 40);
        $second = $service->create(2, 'XL', 'أحمر', 50);
        $this->assertNotEquals($first->sizeId, $second->sizeId);
        $this->assertNotEquals($first->id, $second->id);
        $this->assertEquals(2, $second->size->itemId);
    }

    public function test_blank_label_is_rejected_without_creating_size(): void
    {
        try {
            app(PurchaseVariantService::class)->create(1, 'XL', '  ', 40);
            $this->fail('Blank color must fail.');
        } catch (ValidationException $e) {
            $this->assertEquals(0, DB::table('sizes')->count());
        }
    }

    public function test_deleted_product_cannot_receive_new_variants(): void
    {
        DB::table('products')->where('id', 1)->update(['deleted_at' => now()]);
        $this->expectException(ModelNotFoundException::class);
        app(PurchaseVariantService::class)->create(1, 'XL', 'أحمر', 40);
    }
}
