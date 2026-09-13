<?php

namespace Tests\Feature;

use App\Http\Middleware\RefreshSanctumTokenExpiry;
use App\Models\AppSetting;
use App\Models\Category;
use App\Models\EmployeeDetail;
use App\Models\InstantSale;
use App\Models\InventoryCostAllocation;
use App\Models\InventoryAdjustment;
use App\Models\InventoryCostLayer;
use App\Models\Product;
use App\Models\ProductStockMovement;
use App\Models\PurchasePriceHistory;
use App\Models\PurchaseProduct;
use App\Models\Seller;
use App\Models\Size;
use App\Models\SizeColor;
use App\Models\User;
use App\Services\InventoryAdjustmentService;
use App\Services\InventoryCostingService;
use App\Services\LegacyInventoryAuditService;
use App\Services\ProductStockService;
use App\Services\PurchasingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryArchitectureTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['type' => 'admin']);
        $this->setMethod(InventoryCostingService::METHOD_FIFO);
    }

    public function test_product_creation_defaults_to_zero_and_optional_opening_stock_is_transactional(): void
    {
        $category = $this->category();
        Sanctum::actingAs($this->admin);
        $this->withoutMiddleware(RefreshSanctumTokenExpiry::class);

        $base = [
            'nameAr' => 'منتج بلا مخزون',
            'descriptionAr' => 'اختبار',
            'discount' => 0,
            'normailPrice' => 10,
            'wholesalePrice' => 8,
            'min_stock' => 1,
            'is_sold_with_paper' => 0,
            'category_id' => $category->id,
            'save_scope' => 'local_only',
        ];

        $plain = $this->postJson('/api/create/product', $base)->assertOk();
        $this->assertSame('success', $plain->json('status'), $plain->getContent());
        $plainProduct = Product::query()->findOrFail($plain->json('product_id'));
        $this->assertSame(0, (int) $plainProduct->stock);
        $this->assertFalse(InventoryCostLayer::query()->where('product_id', $plainProduct->id)->exists());

        $opening = $this->postJson('/api/create/product', array_merge($base, [
            'nameAr' => 'منتج برصيد افتتاحي',
            'opening_stock' => true,
            'opening_quantity' => 50,
            'opening_unit_cost' => 4,
            'opening_currency' => 'NIS',
            'opening_notes' => 'رصيد افتتاحي موثق',
        ]))->assertOk()->assertJsonPath('status', 'success');
        $openingProduct = Product::query()->findOrFail($opening->json('product_id'));

        $this->assertSame(50, (int) $openingProduct->stock);
        $this->assertDatabaseHas('inventory_cost_layers', [
            'product_id' => $openingProduct->id,
            'quantity' => 50,
            'remaining_quantity' => 50,
            'unit_cost' => 4,
            'source_type' => 'opening_stock',
        ]);
        $this->assertDatabaseHas('product_stock_movements', [
            'product_id' => $openingProduct->id,
            'type' => ProductStockMovement::TYPE_OPENING_STOCK,
            'stock_before' => 0,
            'stock_after' => 50,
        ]);
    }

    public function test_quick_created_purchase_product_stays_zero_until_one_receipt(): void
    {
        $category = $this->category();
        Sanctum::actingAs($this->admin);
        $this->withoutMiddleware(RefreshSanctumTokenExpiry::class);

        $response = $this->postJson('/api/purchase/products/quick-create', [
            'name' => 'منتج سريع للشراء',
            'category_id' => $category->id,
            'retail_price' => 9,
            'wholesale_price' => 8,
            'minimum_stock' => 2,
        ])->assertOk()->assertJsonPath('status', 'success');
        $product = Product::query()->findOrFail($response->json('product.id'));
        $this->assertSame(0, (int) $product->stock);

        $seller = Seller::query()->create(['name' => 'مورد الاختبار', 'phone' => '05912345']);
        $bill = app(PurchasingService::class)->createPurchase([
            'seller_id' => $seller->id,
            'products' => [[
                'product_id' => $product->id,
                'quantity' => 3,
                'purchase_price' => 5,
            ]],
        ], $this->admin->id);
        $this->assertSame(0, (int) $product->fresh()->stock);

        app(PurchasingService::class)->receive($bill, [
            'items' => [[
                'bill_item_id' => $bill->items()->firstOrFail()->id,
                'accepted_quantity' => 3,
                'unit_price' => 5,
            ]],
        ], $this->admin->id);
        $this->assertSame(3, (int) $product->fresh()->stock);
        $this->assertSame(1, InventoryCostLayer::query()
            ->where('product_id', $product->id)
            ->where('source_type', 'purchase_receipt_item')
            ->count());

        $duplicateRejected = false;
        try {
            app(PurchasingService::class)->receive($bill->fresh(), [
                'items' => [[
                    'bill_item_id' => $bill->items()->firstOrFail()->id,
                    'accepted_quantity' => 3,
                    'unit_price' => 5,
                ]],
            ], $this->admin->id);
        } catch (\RuntimeException) {
            $duplicateRejected = true;
        }
        $this->assertTrue($duplicateRejected, 'A concurrent/retried full receipt must not add stock twice.');
        $this->assertSame(3, (int) $product->fresh()->stock);
    }

    public function test_fifo_multilayer_sale_uses_oldest_cost_and_preserves_remaining_value(): void
    {
        $product = $this->product();
        $costing = app(InventoryCostingService::class);
        $costing->addOwnedStock($product, 50, 3, 'NIS', 'opening_stock', $product->id);
        $costing->addOwnedStock($product, 50, 5, 'NIS', 'purchase_receipt_item', 501);

        $cost = $costing->consumeOwnedStock(
            $product,
            70,
            ProductStockMovement::TYPE_SALE,
            'test_sale',
            701,
        );
        $summary = $costing->productSummary($product->fresh(), true);

        $this->assertEqualsWithDelta(250, $cost['total_cost'], 0.0001);
        $this->assertSame(30, (int) $product->fresh()->stock);
        $this->assertEqualsWithDelta(150, $summary['inventory_value'], 0.0001);
        $this->assertEqualsWithDelta(5, $summary['average_inventory_unit_cost'], 0.0001);
        $this->assertEqualsWithDelta(5, $summary['next_fifo_unit_cost'], 0.0001);
    }

    public function test_moving_average_sale_does_not_recalculate_average_from_remaining_fifo_layers(): void
    {
        $this->setMethod(InventoryCostingService::METHOD_MOVING_AVERAGE);
        $product = $this->product();
        $costing = app(InventoryCostingService::class);
        $costing->addOwnedStock($product, 50, 3, 'NIS', 'opening_stock', $product->id);
        $costing->addOwnedStock($product, 50, 5, 'NIS', 'purchase_receipt_item', 502);

        $cost = $costing->consumeOwnedStock(
            $product,
            20,
            ProductStockMovement::TYPE_SALE,
            'test_sale',
            702,
        );
        $summary = $costing->productSummary($product->fresh(), true);

        $this->assertEqualsWithDelta(80, $cost['total_cost'], 0.0001);
        $this->assertEqualsWithDelta(320, $summary['inventory_value'], 0.0001);
        $this->assertEqualsWithDelta(4, $summary['average_inventory_unit_cost'], 0.0001);
        $this->assertSame(80, (int) $product->fresh()->stock);
    }

    public function test_positive_negative_and_cost_revaluation_adjustments_are_audited_separately(): void
    {
        $product = $this->product();
        $costing = app(InventoryCostingService::class);
        $adjustments = app(InventoryAdjustmentService::class);
        $costing->addOwnedStock($product, 10, 4, 'NIS', 'opening_stock', $product->id);

        $positive = $adjustments->adjustQuantity($product, 15, 'جرد فعلي', 'زيادة', 6, 'NIS', null, $this->admin->id);
        $this->assertSame(5, (int) $positive->quantity_difference);
        $this->assertEqualsWithDelta(70, $positive->new_value, 0.0001);

        $negative = $adjustments->adjustQuantity($product->fresh(), 7, 'تالف', 'نقص', null, 'NIS', null, $this->admin->id);
        $this->assertSame(-8, (int) $negative->quantity_difference);
        $this->assertEqualsWithDelta(38, $negative->new_value, 0.0001);

        $revaluation = $adjustments->revalue($product->fresh(), 5, 'تصحيح محاسبي', 'لا تغيير بالكمية', 'NIS', null, $this->admin->id);
        $this->assertSame(0, (int) $revaluation->quantity_difference);
        $this->assertSame(7, (int) $product->fresh()->stock);
        $this->assertEqualsWithDelta(35, $revaluation->new_value, 0.0001);
        $this->assertDatabaseHas('inventory_cost_revaluation_lines', [
            'inventory_adjustment_id' => $revaluation->id,
            'new_unit_cost' => 5,
        ]);
    }

    public function test_moving_average_adjustment_out_keeps_the_same_average(): void
    {
        $this->setMethod(InventoryCostingService::METHOD_MOVING_AVERAGE);
        $product = $this->product();
        $costing = app(InventoryCostingService::class);
        $adjustments = app(InventoryAdjustmentService::class);
        $costing->addOwnedStock($product, 10, 4, 'NIS', 'opening_stock', $product->id);
        $adjustments->adjustQuantity($product, 15, 'تصحيح فعلي', null, 6, 'NIS', null, $this->admin->id);
        $before = $costing->productSummary($product->fresh(), true);
        $adjustments->adjustQuantity($product->fresh(), 12, 'جرد فعلي', null, null, 'NIS', null, $this->admin->id);
        $after = $costing->productSummary($product->fresh(), true);

        $this->assertEqualsWithDelta(70 / 15, $before['average_inventory_unit_cost'], 0.0001);
        $this->assertEqualsWithDelta($before['average_inventory_unit_cost'], $after['average_inventory_unit_cost'], 0.0001);
        $this->assertEqualsWithDelta(56, $after['inventory_value'], 0.0001);
    }

    public function test_missing_inventory_cost_can_be_initialized_without_changing_stock(): void
    {
        $product = $this->product(10);
        $existingLayer = InventoryCostLayer::query()->create([
            'product_id' => $product->id,
            'quantity' => 2,
            'remaining_quantity' => 2,
            'unit_cost' => 5,
            'original_unit_cost' => 5,
            'currency' => 'شيكل',
            'source_type' => 'purchase_receipt_item',
            'source_id' => 7001,
            'effective_at' => now(),
        ]);

        $adjustment = app(InventoryAdjustmentService::class)->initializeMissingCost(
            $product,
            3,
            'اعتماد تكلفة المخزون القديم',
            'اعتماد موثق من صاحب المتجر',
            'NIS',
            null,
            $this->admin->id,
        );
        $summary = app(InventoryCostingService::class)->productSummary($product->fresh(), true);
        $openingLayer = InventoryCostLayer::query()
            ->where('product_id', $product->id)
            ->where('source_type', 'inventory_cost_initialization')
            ->sole();

        $this->assertSame(10, (int) $product->fresh()->stock);
        $this->assertSame(InventoryAdjustment::TYPE_COST_INITIALIZATION, $adjustment->adjustment_type);
        $this->assertSame(0, (int) $adjustment->quantity_difference);
        $this->assertEqualsWithDelta(8, $openingLayer->remaining_quantity, 0.0001);
        $this->assertTrue($openingLayer->effective_at->lt($existingLayer->effective_at));
        $this->assertEqualsWithDelta(34, $summary['inventory_value'], 0.0001);
        $this->assertEqualsWithDelta(3.4, $summary['average_inventory_unit_cost'], 0.0001);
        $this->assertEqualsWithDelta(3, $summary['next_fifo_unit_cost'], 0.0001);
        $this->assertEqualsWithDelta(0, $summary['missing_cost_quantity'], 0.0001);
        $this->assertTrue($summary['cost_coverage_complete']);
        $this->assertDatabaseHas('product_stock_movements', [
            'product_id' => $product->id,
            'type' => ProductStockMovement::TYPE_COST_INITIALIZATION,
            'quantity' => 0,
            'stock_before' => 10,
            'stock_after' => 10,
        ]);

        try {
            app(InventoryAdjustmentService::class)->initializeMissingCost(
                $product->fresh(), 3, 'محاولة مكررة', null, 'NIS', null, $this->admin->id,
            );
            $this->fail('Fully covered inventory must not receive duplicate cost coverage.');
        } catch (ValidationException) {
            // Expected: no duplicate layer or value is created.
        }

        $this->assertSame(1, InventoryCostLayer::query()
            ->where('product_id', $product->id)
            ->where('source_type', 'inventory_cost_initialization')
            ->count());
    }

    public function test_inventory_cost_initialization_permission_is_enforced_by_backend(): void
    {
        $employeeUser = User::factory()->create(['type' => 'employee']);
        EmployeeDetail::query()->create(['user_id' => $employeeUser->id]);
        $product = $this->product(5);
        Sanctum::actingAs($employeeUser);
        $this->withoutMiddleware(RefreshSanctumTokenExpiry::class);

        $this->postJson('/api/product/inventory/initialize-cost', [
            'product_id' => $product->id,
            'unit_cost' => 4,
            'reason' => 'غير مصرح',
        ])->assertOk()->assertJsonPath('status', 'error');

        $this->assertSame(5, (int) $product->fresh()->stock);
        $this->assertFalse(InventoryCostLayer::query()
            ->where('product_id', $product->id)
            ->where('source_type', 'inventory_cost_initialization')
            ->exists());
    }

    public function test_variant_costing_never_consumes_another_variant_layer(): void
    {
        $product = $this->product();
        $size = Size::query()->create([
            'id' => (int) (Size::query()->max('id') ?? 0) + 1,
            'itemId' => $product->id,
            'size' => 'M',
        ]);
        $red = $this->variant($size, 'Red');
        $blue = $this->variant($size, 'Blue');
        $costing = app(InventoryCostingService::class);
        $costing->addOwnedStock($product, 10, 3, 'NIS', 'opening_stock', $product->id, $red->id, $size->id);
        $costing->addOwnedStock($product, 10, 7, 'NIS', 'opening_stock', $product->id, $blue->id, $size->id);

        $costing->consumeOwnedStock(
            $product,
            4,
            ProductStockMovement::TYPE_SALE,
            'test_sale',
            703,
            $red->id,
            $size->id,
        );

        $this->assertSame(6, (int) $red->fresh()->stock);
        $this->assertSame(10, (int) $blue->fresh()->stock);
        $this->assertEqualsWithDelta(10, InventoryCostLayer::query()
            ->where('size_color_id', $blue->id)->sum('remaining_quantity'), 0.0001);
        $this->assertFalse(InventoryCostAllocation::query()
            ->where('reference_id', 703)
            ->where('size_color_id', $blue->id)
            ->exists());
    }

    public function test_serialized_outflows_cannot_drive_stock_or_cost_quantity_negative(): void
    {
        $product = $this->product();
        $costing = app(InventoryCostingService::class);
        $costing->addOwnedStock($product, 2, 3, 'NIS', 'opening_stock', $product->id);
        $costing->consumeOwnedStock($product, 2, ProductStockMovement::TYPE_SALE, 'test_sale', 800);

        try {
            // Represents a stale second writer that started from the old quantity.
            $costing->consumeOwnedStock($product->fresh(), 1, ProductStockMovement::TYPE_SALE, 'test_sale', 801);
            $this->fail('The second outflow should be rejected after the locked balance reaches zero.');
        } catch (ValidationException) {
            // Expected: the transaction is rolled back without a partial stock or cost write.
        }

        $this->assertSame(0, (int) $product->fresh()->stock);
        $this->assertEqualsWithDelta(0, InventoryCostLayer::query()
            ->where('product_id', $product->id)->sum('remaining_quantity'), 0.0001);
        $this->assertFalse(ProductStockMovement::query()
            ->where('reference_type', 'test_sale')
            ->where('reference_id', 801)
            ->exists());
    }

    public function test_sale_return_restores_original_cogs_without_rewriting_history(): void
    {
        $product = $this->product();
        $costing = app(InventoryCostingService::class);
        $costing->addOwnedStock($product, 10, 5, 'NIS', 'opening_stock', $product->id);
        $sale = InstantSale::query()->create([
            'product_id' => $product->id,
            'quantity' => 3,
            'cost' => 10,
            'total_cost' => 30,
            'type' => 'normal',
            'buyer_type' => 'unknown',
            'buyer_name' => '-',
            'status' => 'active',
        ]);
        $stock = app(ProductStockService::class);
        $stock->deductForSale($product, 3, referenceType: 'instant_sale', referenceId: $sale->id);
        $originalCogs = (float) $sale->fresh()->inventory_total_cost;
        $stock->restoreForSale($product->fresh(), 2, referenceType: 'instant_sale', referenceId: $sale->id, costSourceType: 'sales_return');

        $this->assertEqualsWithDelta(15, $originalCogs, 0.0001);
        $this->assertEqualsWithDelta($originalCogs, (float) $sale->fresh()->inventory_total_cost, 0.0001);
        $this->assertSame(9, (int) $product->fresh()->stock);
    }

    public function test_stock_adjustment_permission_is_enforced_by_backend(): void
    {
        $employeeUser = User::factory()->create(['type' => 'employee']);
        EmployeeDetail::query()->create(['user_id' => $employeeUser->id]);
        $product = $this->product();
        Sanctum::actingAs($employeeUser);
        $this->withoutMiddleware(RefreshSanctumTokenExpiry::class);

        $this->postJson('/api/product/stock/adjust', [
            'product_id' => $product->id,
            'actual_quantity' => 1,
            'unit_cost' => 1,
            'reason' => 'غير مصرح',
        ])->assertOk()->assertJsonPath('status', 'error');
        $this->assertSame(0, (int) $product->fresh()->stock);
    }

    public function test_legacy_backfill_is_idempotent_and_unknown_cost_requires_review(): void
    {
        Storage::fake('local');
        $known = $this->product(6);
        PurchaseProduct::query()->create([
            'product_id' => $known->id,
            'seller_id' => null,
            'price' => 2,
        ]);
        $unknown = $this->product(4);

        $this->artisan('inventory:backfill-cost-layers', [
            '--write' => true,
            '--product' => [$known->id, $unknown->id],
        ])->assertSuccessful();
        $this->artisan('inventory:backfill-cost-layers', [
            '--write' => true,
            '--product' => [$known->id, $unknown->id],
        ])->assertSuccessful();

        $this->assertSame(1, InventoryCostLayer::query()
            ->where('product_id', $known->id)
            ->where('source_type', 'opening_stock_backfill')
            ->count());
        $this->assertDatabaseHas('inventory_cost_reviews', [
            'product_id' => $unknown->id,
            'status' => 'pending',
            'reason' => 'reliable_opening_unit_cost_not_found',
        ]);
    }

    public function test_legacy_inventory_audit_web_page_requires_token_and_is_read_only(): void
    {
        $product = $this->product(6);
        PurchaseProduct::query()->create([
            'product_id' => $product->id,
            'seller_id' => null,
            'price' => 2,
        ]);
        $layersBefore = InventoryCostLayer::query()->count();

        $this->get('/inventory/legacy-audit')->assertForbidden();

        $this->get('/inventory/legacy-audit?token=eshterelyDeploy2026SecureToken123')
            ->assertOk()
            ->assertSee('مراجعة تغطية تكلفة المخزون القديم')
            ->assertSee('جاهز لإنشاء طبقة');
        $this->get('/inventory/legacy-audit/review-workbook?token=eshterelyDeploy2026SecureToken123')
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $this->assertSame(6, (int) $product->fresh()->stock);
        $this->assertSame($layersBefore, InventoryCostLayer::query()->count());

    }

    public function test_legacy_ready_batch_preserves_stock_is_idempotent_and_places_opening_layer_first(): void
    {
        $product = $this->product(10);
        PurchaseProduct::query()->create(['product_id' => $product->id, 'seller_id' => null, 'price' => 3]);
        $receiptLayer = InventoryCostLayer::query()->create([
            'product_id' => $product->id,
            'quantity' => 2,
            'remaining_quantity' => 2,
            'unit_cost' => 5,
            'original_unit_cost' => 5,
            'currency' => 'شيكل',
            'source_type' => 'purchase_receipt_item',
            'source_id' => 999,
            'effective_at' => now(),
        ]);

        $service = app(LegacyInventoryAuditService::class);
        $first = $service->applyReadyBatch(10, 'Inventory tester');
        $second = $service->applyReadyBatch(10, 'Inventory tester');

        $this->assertSame(1, $first['created']);
        $this->assertSame(0, $second['created']);
        $this->assertSame(10, (int) $product->fresh()->stock);
        $opening = InventoryCostLayer::query()
            ->where('product_id', $product->id)
            ->where('source_type', 'opening_stock_backfill')
            ->sole();
        $this->assertSame(8.0, (float) $opening->remaining_quantity);
        $this->assertTrue($opening->effective_at->lt($receiptLayer->effective_at));
        $this->assertDatabaseHas('inventory_cost_balances', [
            'product_id' => $product->id,
            'quantity' => 10,
            'inventory_value' => 34,
            'needs_review' => 0,
        ]);
        $this->assertDatabaseHas('product_stock_movements', [
            'product_id' => $product->id,
            'type' => ProductStockMovement::TYPE_OPENING_STOCK,
            'quantity' => 0,
            'stock_before' => 10,
            'stock_after' => 10,
        ]);
        $this->assertDatabaseHas('inventory_cost_reviews', [
            'product_id' => $product->id,
            'status' => 'resolved',
            'missing_quantity' => 0,
        ]);
    }

    public function test_reviewed_variant_cost_is_explicit_and_never_uses_product_cost_automatically(): void
    {
        $product = $this->product();
        $size = Size::query()->create([
            'id' => (int) (Size::query()->max('id') ?? 0) + 1,
            'itemId' => $product->id,
            'size' => 'M',
        ]);
        $variant = $this->variant($size, 'أحمر');
        $variant->update(['stock' => 7]);
        PurchaseProduct::query()->create(['product_id' => $product->id, 'seller_id' => null, 'price' => 4]);
        PurchasePriceHistory::query()->create([
            'product_id' => $product->id,
            'unit_price' => 6,
            'quantity' => 7,
            'currency' => 'شيكل',
            'priced_at' => now()->toDateString(),
        ]);

        $service = app(LegacyInventoryAuditService::class);
        $row = collect($service->run(false)['rows'])
            ->firstWhere('identity_key', app(InventoryCostingService::class)->identityKey($product->id, $variant->id));

        $this->assertSame('review', $row['status']);
        $this->assertNull($row['suggested_unit_cost']);
        $this->assertSame(4.0, (float) $row['reference_unit_cost']);
        $write = $service->run(true, [$product->id]);
        $this->assertSame(0, $write['created_layers']);
        $this->assertFalse(InventoryCostLayer::query()->where('product_id', $product->id)->exists());

        $service->applyReviewedCost(
            $row['identity_key'],
            5,
            'NIS',
            'Inventory tester',
            'مراجعة تكلفة المتغير',
            'تم التحقق من فاتورة المورد',
        );

        $this->assertSame(7, (int) $variant->fresh()->stock);
        $this->assertSame(0, (int) $product->fresh()->stock);
        $this->assertDatabaseHas('inventory_cost_layers', [
            'product_id' => $product->id,
            'size_color_id' => $variant->id,
            'remaining_quantity' => 7,
            'unit_cost' => 5,
            'source_type' => 'opening_stock_backfill',
        ]);
        $this->assertFalse(InventoryCostLayer::query()
            ->where('product_id', $product->id)
            ->whereNull('size_color_id')
            ->exists());
    }

    public function test_approved_product_reference_batch_covers_only_eligible_variants_and_is_idempotent(): void
    {
        $product = $this->product();
        $size = Size::query()->create([
            'id' => (int) (Size::query()->max('id') ?? 0) + 1,
            'itemId' => $product->id,
            'size' => 'M',
        ]);
        $red = $this->variant($size, 'أحمر');
        $blue = $this->variant($size, 'أزرق');
        $red->update(['stock' => 2]);
        $blue->update(['stock' => 3]);
        $purchase = PurchaseProduct::query()->create([
            'product_id' => $product->id,
            'seller_id' => null,
            'price' => 4,
        ]);

        $withoutReference = $this->product();
        $otherSize = Size::query()->create([
            'id' => (int) (Size::query()->max('id') ?? 0) + 1,
            'itemId' => $withoutReference->id,
            'size' => 'L',
        ]);
        $otherVariant = $this->variant($otherSize, 'أسود');
        $otherVariant->update(['stock' => 5]);

        $service = app(LegacyInventoryAuditService::class);
        $first = $service->applyProductReferenceBatch(50, 'Inventory tester');
        $second = $service->applyProductReferenceBatch(50, 'Inventory tester');

        $this->assertSame(2, $first['selected']);
        $this->assertSame(2, $first['created'], json_encode($first, JSON_UNESCAPED_UNICODE));
        $this->assertSame(0, $first['failed']);
        $this->assertSame(0, $second['selected']);
        $this->assertSame(0, (int) $product->fresh()->stock);
        $this->assertSame(2, (int) $red->fresh()->stock);
        $this->assertSame(3, (int) $blue->fresh()->stock);
        $this->assertSame(5, (int) $otherVariant->fresh()->stock);
        $this->assertSame(2, InventoryCostLayer::query()
            ->where('product_id', $product->id)
            ->where('source_type', 'opening_stock_backfill')
            ->count());
        $this->assertDatabaseHas('inventory_cost_layers', [
            'product_id' => $product->id,
            'size_color_id' => $red->id,
            'remaining_quantity' => 2,
            'unit_cost' => 4,
        ]);
        $this->assertDatabaseHas('inventory_cost_layers', [
            'product_id' => $product->id,
            'size_color_id' => $blue->id,
            'remaining_quantity' => 3,
            'unit_cost' => 4,
        ]);
        $this->assertFalse(InventoryCostLayer::query()
            ->where('product_id', $withoutReference->id)
            ->exists());
        $this->assertSame(2, ProductStockMovement::query()
            ->where('product_id', $product->id)
            ->where('type', ProductStockMovement::TYPE_OPENING_STOCK)
            ->where('quantity', 0)
            ->count());
        $review = \App\Models\InventoryCostReview::query()
            ->where('identity_key', app(InventoryCostingService::class)->identityKey($product->id, $red->id))
            ->sole();
        $this->assertSame('resolved', $review->status);
        $this->assertSame('purchase_products.product_level_reference', $review->evidence['cost_source']);
        $this->assertSame($purchase->id, $review->evidence['cost_source_id']);
    }

    public function test_product_reference_batch_web_action_requires_token_and_confirmation(): void
    {
        $product = $this->product();
        $size = Size::query()->create([
            'id' => (int) (Size::query()->max('id') ?? 0) + 1,
            'itemId' => $product->id,
            'size' => 'M',
        ]);
        $variant = $this->variant($size, 'أحمر');
        $variant->update(['stock' => 2]);
        PurchaseProduct::query()->create(['product_id' => $product->id, 'seller_id' => null, 'price' => 4]);
        $payload = [
            'operator' => 'Inventory tester',
            'batch_size' => 50,
            'backup_confirmed' => 1,
            'confirmation' => 'VARIANTS',
        ];

        $this->post('/inventory/legacy-audit/product-reference-batch', $payload)->assertForbidden();
        $this->post('/inventory/legacy-audit/product-reference-batch', array_merge($payload, [
            'token' => 'eshterelyDeploy2026SecureToken123',
            'confirmation' => 'WRONG',
        ]))->assertSessionHasErrors('confirmation');
        $this->assertFalse(InventoryCostLayer::query()->where('product_id', $product->id)->exists());

        $this->post('/inventory/legacy-audit/product-reference-batch', array_merge($payload, [
            'token' => 'eshterelyDeploy2026SecureToken123',
        ]))->assertRedirect();
        $this->assertSame(2, (int) $variant->fresh()->stock);
        $this->assertDatabaseHas('inventory_cost_layers', [
            'product_id' => $product->id,
            'size_color_id' => $variant->id,
            'remaining_quantity' => 2,
            'unit_cost' => 4,
        ]);
    }

    public function test_reviewed_workbook_preserves_stock_records_evidence_and_rejects_stale_quantity(): void
    {
        $product = $this->product(3);
        $service = app(LegacyInventoryAuditService::class);
        $group = collect($service->manualReviewGroups())->firstWhere('product_id', $product->id);
        $approved = array_merge($group, [
            'unit_cost' => 7,
            'currency' => 'شيكل',
            'cost_evidence' => 'فاتورة المورد رقم 25',
            'notes' => 'راجعها صاحب المتجر',
        ]);

        $result = $service->applyReviewedWorkbook([$approved], 'Inventory tester', 'workbook-sha256');

        $this->assertSame(1, $result['selected_groups']);
        $this->assertSame(1, $result['created']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame(3, (int) $product->fresh()->stock);
        $this->assertDatabaseHas('inventory_cost_layers', [
            'product_id' => $product->id,
            'remaining_quantity' => 3,
            'unit_cost' => 7,
            'source_type' => 'opening_stock_backfill',
        ]);
        $review = \App\Models\InventoryCostReview::query()
            ->where('identity_key', app(InventoryCostingService::class)->identityKey($product->id))
            ->sole();
        $this->assertSame('administrative_review_workbook', $review->evidence['cost_source']);
        $this->assertSame('workbook-sha256', $review->evidence['workbook_sha256']);
        $this->assertSame('فاتورة المورد رقم 25', $review->evidence['owner_cost_evidence']);

        $stale = $this->product(2);
        $staleGroup = collect($service->manualReviewGroups())->firstWhere('product_id', $stale->id);
        $stale->update(['stock' => 4]);
        $staleResult = $service->applyReviewedWorkbook([array_merge($staleGroup, [
            'unit_cost' => 5,
            'currency' => 'شيكل',
            'cost_evidence' => 'اعتماد صاحب المتجر',
        ])], 'Inventory tester', 'stale-workbook');

        $this->assertSame(1, $staleResult['failed']);
        $this->assertFalse(InventoryCostLayer::query()->where('product_id', $stale->id)->exists());
        $this->assertSame(4, (int) $stale->fresh()->stock);
    }

    public function test_legacy_audit_web_batch_requires_token_backup_and_confirmation(): void
    {
        $product = $this->product(3);
        PurchaseProduct::query()->create(['product_id' => $product->id, 'seller_id' => null, 'price' => 2]);

        $this->post('/inventory/legacy-audit/backfill-ready')->assertForbidden();
        $this->post('/inventory/legacy-audit/backfill-ready', [
            'token' => 'eshterelyDeploy2026SecureToken123',
            'operator' => 'Inventory tester',
            'batch_size' => 10,
            'backup_confirmed' => 1,
            'confirmation' => 'BACKFILL',
        ])->assertRedirect();

        $this->assertSame(3, (int) $product->fresh()->stock);
        $this->assertDatabaseHas('inventory_cost_layers', [
            'product_id' => $product->id,
            'remaining_quantity' => 3,
            'unit_cost' => 2,
            'source_type' => 'opening_stock_backfill',
        ]);

        $reviewedProduct = $this->product(2);
        $identityKey = app(InventoryCostingService::class)->identityKey($reviewedProduct->id);
        $this->post('/inventory/legacy-audit/reviewed-cost', [
            'identity_key' => $identityKey,
            'unit_cost' => 7,
            'currency' => 'شيكل',
            'operator' => 'Inventory tester',
            'reason' => 'مراجعة فاتورة قديمة',
            'backup_confirmed' => 1,
            'confirmation' => 'REVIEW',
        ])->assertForbidden();
        $this->post('/inventory/legacy-audit/reviewed-cost', [
            'token' => 'eshterelyDeploy2026SecureToken123',
            'identity_key' => $identityKey,
            'unit_cost' => 7,
            'currency' => 'شيكل',
            'operator' => 'Inventory tester',
            'reason' => 'مراجعة فاتورة قديمة',
            'backup_confirmed' => 1,
            'confirmation' => 'REVIEW',
        ])->assertRedirect();
        $this->assertSame(2, (int) $reviewedProduct->fresh()->stock);
        $this->assertDatabaseHas('inventory_cost_layers', [
            'product_id' => $reviewedProduct->id,
            'remaining_quantity' => 2,
            'unit_cost' => 7,
            'source_type' => 'opening_stock_backfill',
        ]);

        $this->get('/inventory/legacy-audit/export?token=eshterelyDeploy2026SecureToken123')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    private function setMethod(string $method): void
    {
        AppSetting::query()->updateOrCreate(
            ['key' => AppSetting::KEY_INVENTORY_COSTING_METHOD],
            ['value' => $method],
        );
    }

    private function product(int $stock = 0): Product
    {
        $id = (int) (Product::withTrashed()->max('id') ?? 0) + 1;

        return Product::withoutEvents(fn () => Product::query()->create([
            'id' => $id,
            'product_code' => (string) $id,
            'nameAr' => 'Inventory '.$id,
            'nameEng' => 'Inventory '.$id,
            'stock' => $stock,
            'normailPrice' => 10,
            'wholesalePrice' => 8,
        ]));
    }

    private function category(): Category
    {
        return Category::query()->create([
            'id' => (int) (Category::query()->max('id') ?? 0) + 1,
            'nameAr' => 'Inventory Test',
            'nameEng' => 'Inventory Test',
        ]);
    }

    private function variant(Size $size, string $color): SizeColor
    {
        return SizeColor::query()->create([
            'id' => (int) (SizeColor::query()->max('id') ?? 0) + 1,
            'sizeId' => $size->id,
            'colorAr' => $color,
            'colorEn' => $color,
            'colorAbbr' => $color,
            'normailPrice' => 10,
            'wholesalePrice' => 8,
            'discount' => 0,
            'stock' => 0,
        ]);
    }
}
