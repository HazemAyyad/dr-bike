<?php

namespace Tests\Unit;

use App\Http\Controllers\API\Stocks;
use App\Models\Product;
use App\Services\StockImagesZipExportService;
use App\Support\ProductStockStatusFilter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProductStockStatusFilterTest extends TestCase
{
    private string $previous;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previous = config('database.default');
        config(['database.default' => 'stock_status_test', 'database.connections.stock_status_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
        ]]);
        DB::purge('stock_status_test');
        DB::setDefaultConnection('stock_status_test');
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->integer('stock');
            $table->unsignedBigInteger('category_id');
            $table->softDeletes();
            $table->timestamps();
        });
        foreach ([[1, 0, 1], [2, -1, 1], [3, -8, 2], [4, 6, 1], [5, 0, 2], [6, -2, 1]] as [$id, $stock, $category]) {
            DB::table('products')->insert(['id' => $id, 'stock' => $stock, 'category_id' => $category,
                'deleted_at' => $id === 6 ? now() : null]);
        }
    }

    protected function tearDown(): void
    {
        DB::purge('stock_status_test');
        DB::setDefaultConnection($this->previous);
        parent::tearDown();
    }

    public function test_zero_filter_is_exact_and_preserves_other_constraints(): void
    {
        $this->assertEquals([1, 5], ProductStockStatusFilter::apply(Product::query(), 'zero')->pluck('id')->all());
        $this->assertEquals([1], ProductStockStatusFilter::apply(Product::where('category_id', 1), 'zero')->pluck('id')->all());
    }

    public function test_negative_filter_excludes_zero_positive_and_deleted_products(): void
    {
        $query = ProductStockStatusFilter::apply(Product::query(), 'negative')->orderBy('id');
        $page = $query->paginate(1, ['*'], 'page', 2);
        $this->assertEquals(2, $page->total());
        $this->assertEquals([3], $page->pluck('id')->all());
    }

    public function test_no_stock_filter_keeps_all_visible_products(): void
    {
        foreach ([null, '', 'unknown'] as $status) {
            $this->assertEquals([1, 2, 3, 4, 5], ProductStockStatusFilter::apply(Product::query(), $status)->pluck('id')->all());
        }
    }

    public function test_product_exports_and_quick_edit_use_the_same_filter(): void
    {
        $reflection = new \ReflectionClass(Stocks::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $query = Product::query();
        $request = Request::create('/', 'GET', ['stock_status' => 'negative', 'category_id' => 1]);
        $reflection->getMethod('applyProductExportFilters')->invoke($controller, $query, $request);
        $this->assertEquals([2], $query->pluck('id')->all());
    }

    public function test_image_export_persists_stock_filter_for_queued_jobs(): void
    {
        $service = new StockImagesZipExportService;
        foreach (['zero' => [1], 'negative' => [2]] as $status => $ids) {
            $filters = $service->filtersFromRequest(Request::create('/', 'POST', ['stock_status' => $status, 'category_id' => 1]));
            $this->assertEquals($status, $filters['stock_status']);
            $query = Product::query();
            $service->applyFilters($query, $filters, false);
            $this->assertEquals($ids, $query->pluck('id')->all());
        }
    }
}
