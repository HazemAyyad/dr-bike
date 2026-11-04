<?php

namespace Tests\Feature;

use App\Http\Middleware\RefreshSanctumTokenExpiry;
use App\Models\EmployeeDetail;
use App\Models\InstantSale;
use App\Models\OfferPackage;
use App\Models\Product;
use App\Models\ProductStockMovement;
use App\Models\User;
use App\Services\InventoryCostingService;
use App\Services\ProductSaleStatusService;
use App\Services\SalesDailySessionService;
use App\Services\SalesOrderNotificationService;
use App\Services\SalesOrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class ProductSaleStatusTest extends TestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->refreshStoreSchema();
        Http::preventStrayRequests();
        $this->withoutMiddleware(RefreshSanctumTokenExpiry::class);
        $this->admin = User::factory()->create(['type' => 'admin']);
        Sanctum::actingAs($this->admin);
        $this->app->instance(SalesOrderNotificationService::class,
            Mockery::mock(SalesOrderNotificationService::class)->shouldIgnoreMissing());
    }

    public function test_status_toggle_is_audited_and_does_not_change_stock_cost_or_history(): void
    {
        $product = $this->product();
        $sale = InstantSale::withoutEvents(fn () => InstantSale::create([
            'product_id' => $product->id, 'quantity' => 1, 'cost' => 60, 'total_cost' => 60,
        ]));
        $history = $sale->fresh()->getRawOriginal();
        $inventory = app(InventoryCostingService::class)->productSummary($product);
        $this->postJson('/api/product/sale-status', ['product_id' => $product->id, 'is_sale_blocked' => true])
            ->assertOk()->assertJsonPath('status', 'success')->assertJsonPath('is_sale_blocked', true);
        $this->assertSame(10, (int) $product->fresh()->stock);
        $this->assertSame($history, $sale->fresh()->getRawOriginal());
        $this->assertSame($inventory, app(InventoryCostingService::class)->productSummary($product->fresh()));
        $this->assertDatabaseHas('product_stock_movements', [
            'product_id' => $product->id, 'reference_type' => 'product_sale_status', 'quantity' => 0,
            'created_by' => $this->admin->id,
        ]);
        app(ProductSaleStatusService::class)->setBlocked($product->id, true, $this->admin->id);
        $this->assertSame(1, ProductStockMovement::where('reference_type', 'product_sale_status')->count());
        $this->postJson('/api/product/sale-status', ['product_id' => $product->id, 'is_sale_blocked' => false])
            ->assertJsonPath('is_sale_blocked', false);
    }

    public function test_employee_without_stock_permission_cannot_toggle_status(): void
    {
        $product = $this->product();
        $user = User::factory()->create(['type' => 'employee']);
        EmployeeDetail::create(['user_id' => $user->id]);
        Sanctum::actingAs($user);
        $this->postJson('/api/product/sale-status', ['product_id' => $product->id, 'is_sale_blocked' => true])
            ->assertOk()->assertJsonPath('status', 'error');
        $this->assertFalse($product->fresh()->is_sale_blocked);
    }

    public function test_inventory_filters_include_blocked_products_but_sale_picker_excludes_them(): void
    {
        $allowed = $this->product();
        $blocked = $this->product(true);
        $all = $this->getJson('/api/get/products/list')->assertJsonPath('status', 'success')->json('products');
        $this->assertCount(2, $all);
        $rows = $this->getJson('/api/get/products/list?sale_status=blocked')->assertJsonPath('status', 'success')->json('products');
        $this->assertSame([$blocked->id], array_column($rows, 'product_id'));
        $this->assertTrue($rows[0]['is_sale_blocked']);
        $search = $this->postJson('/api/search/products/by/name?name='.urlencode('خوذة').'&sale_status=blocked')
            ->assertJsonPath('status', 'success')->json('products');
        $this->assertSame([$blocked->id], array_column($search, 'product_id'));
        $this->assertTrue($search[0]['is_sale_blocked']);
        $rows = $this->getJson('/api/get/products/list?sale_status=allowed')->json('products');
        $this->assertSame([$allowed->id], array_column($rows, 'product_id'));
        $picker = $this->getJson('/api/all/products')->assertJsonPath('status', 'success')->json('products');
        $this->assertCount(1, $picker);
        $this->assertEquals($allowed->id, $picker[0]['id']);
        $this->postJson('/api/get/product/details', ['product_id' => $blocked->id])
            ->assertJsonPath('status', 'success')->assertJsonPath('product.is_sale_blocked', true);
    }

    public function test_stale_instant_sale_request_is_rejected_before_stock_or_invoice_changes(): void
    {
        $product = $this->product(true);
        $this->openSales();
        $this->postJson('/api/create/instant/sale', $this->salePayload($product))
            ->assertOk()->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'المنتج «'.$product->nameAr.'» ممنوع من البيع.');
        $this->assertSame(0, InstantSale::count());
        $this->assertSame(10, (int) $product->fresh()->stock);
    }

    public function test_blocked_additional_line_and_package_cannot_bypass_the_sale_guard(): void
    {
        $allowed = $this->product();
        $blocked = $this->product(true);
        $this->openSales();
        $payload = $this->salePayload($allowed);
        $payload['other_products'] = [['product_id' => $blocked->id, 'quantity' => 1, 'cost' => 60, 'type' => 'normal']];
        $payload['total_cost'] = $payload['payment_box_value'] = 120;
        $this->postJson('/api/create/instant/sale', $payload)->assertJsonPath('status', 'error');
        $package = OfferPackage::create(['name' => 'باكيج', 'price' => 60, 'package_quantity' => 10, 'is_active' => true]);
        $package->items()->create(['product_id' => $blocked->id, 'quantity' => 1]);
        $this->getJson('/api/offer/packages/for-sale')->assertJsonPath('packages', []);
        $payload = $this->salePayload($blocked);
        unset($payload['product_id']);
        $payload['offer_package_id'] = $package->id;
        $this->postJson('/api/create/instant/sale', $payload)->assertJsonPath('status', 'error');
        $this->assertSame(0, InstantSale::count());
    }

    public function test_old_sale_can_still_be_cancelled_after_blocking_product(): void
    {
        $product = $this->product();
        $this->openSales();
        $this->postJson('/api/create/instant/sale', $this->salePayload($product))->assertJsonPath('status', 'success');
        $sale = InstantSale::where('product_id', $product->id)->sole();
        $this->assertSame(9, (int) $product->fresh()->stock);
        app(ProductSaleStatusService::class)->setBlocked($product->id, true, $this->admin->id);
        $this->postJson('/api/cancel/instant/sale', ['instant_sale_id' => $sale->id])->assertJsonPath('status', 'success');
        $this->assertTrue($sale->fresh()->isCancelled());
        $this->assertSame(10, (int) $product->fresh()->stock);
    }

    public function test_new_order_is_rejected_but_existing_order_can_keep_its_blocked_lines(): void
    {
        $product = $this->product();
        $service = app(SalesOrderService::class);
        $items = [['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 60]];
        $order = $service->store($this->admin, new Request(['customer_name' => 'اختبار', 'reserve_stock' => false, 'items' => $items]));
        app(ProductSaleStatusService::class)->setBlocked($product->id, true, $this->admin->id);
        $updated = $service->update($this->admin, $order->id, new Request(['items' => $items]));
        $this->assertSame(2, (int) $updated->items->sole()->quantity);
        try {
            $service->store($this->admin, new Request(['reserve_stock' => false, 'items' => $items]));
            $this->fail('A blocked product must not enter a new order.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('product_id', $e->errors());
        }
        $items[0]['quantity'] = 3;
        try {
            $service->update($this->admin, $order->id, new Request(['items' => $items]));
            $this->fail('A blocked product quantity must not be increased.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('product_id', $e->errors());
        }
        $this->assertSame(2, (int) $order->items()->sole()->quantity);
    }

    public function test_duplicate_lines_and_changed_variants_do_not_bypass_the_quantity_guard(): void
    {
        $product = $this->product(true);
        $service = app(ProductSaleStatusService::class);
        $existing = [['product_id' => $product->id, 'size_color_id' => 1, 'quantity' => 2]];
        $service->assertLinesAllowed($existing, $existing);
        foreach ([
            array_merge($existing, $existing),
            [['product_id' => $product->id, 'size_color_id' => 2, 'quantity' => 1]],
        ] as $lines) {
            try {
                $service->assertLinesAllowed($lines, $existing);
                $this->fail('A new blocked identity must be rejected.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('product_id', $e->errors());
            }
        }
    }

    public function test_blocked_product_is_unavailable_in_store_but_its_record_still_exists(): void
    {
        $product = $this->product(true);
        $availability = app(\App\Services\OnlineStore\StoreAvailabilityService::class)->resolve($product);
        $this->assertFalse($availability['visible']);
        $this->assertFalse($availability['purchasable']);
        $this->assertNotNull(Product::find($product->id));
        $this->assertSame(10, (int) $product->fresh()->stock);
    }

    public function test_image_export_preserves_the_sale_status_filter(): void
    {
        $this->product();
        $blocked = $this->product(true);
        $service = app(\App\Services\StockImagesZipExportService::class);
        $filters = $service->filtersFromRequest(new Request(['sale_status' => 'blocked']));
        $this->assertSame('blocked', $filters['sale_status']);
        $query = Product::query();
        $service->applyFilters($query, $filters);
        $this->assertSame([$blocked->id], $query->pluck('id')->all());
    }

    private function product(bool $blocked = false): Product
    {
        $id = (int) (Product::withTrashed()->max('id') ?? 0) + 1;
        $product = Product::withoutEvents(fn () => Product::create([
            'id' => $id, 'product_code' => (string) $id, 'nameAr' => 'خوذة '.$id, 'nameEng' => 'Helmet '.$id,
            'normailPrice' => 60, 'stock' => 0, 'is_sale_blocked' => $blocked,
        ]));
        Product::withoutEvents(fn () => app(InventoryCostingService::class)->addOwnedStock(
            product: $product, quantity: 10, unitCost: 28, currency: 'شيكل', sourceType: 'opening_stock', sourceId: null,
        ));

        return $product->fresh();
    }

    private function openSales(): void
    {
        app(SalesDailySessionService::class)->openSession($this->admin, confirmOpeningVariance: true);
    }

    private function salePayload(Product $product): array
    {
        return ['product_id' => $product->id, 'quantity' => 1, 'cost' => 60, 'discount' => 0,
            'total_cost' => 60, 'payment_box_value' => 60, 'type' => 'normal', 'buyer_type' => 'unknown'];
    }
}
