<?php

namespace Tests\Unit;

use App\Models\Box;
use App\Models\InstantSale;
use App\Models\InstantSaleRevision;
use App\Models\InventoryAdjustment;
use App\Models\InventoryCostAllocation;
use App\Models\InventoryCostLayer;
use App\Models\Product;
use App\Models\ProductStockMovement;
use App\Models\Size;
use App\Models\SizeColor;
use App\Models\User;
use App\Services\InventoryAdjustmentService;
use App\Services\InventoryCostingService;
use App\Services\InventoryCostIntegrityService;
use App\Services\MissingSaleCostService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MissingSaleCostServiceTest extends TestCase
{
    private string $previous;

    private User $actor;

    private Product $product;

    private Box $box;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previous = config('database.default');
        config(['database.default' => 'missing_sale_cost_test', 'database.connections.missing_sale_cost_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
        ]]);
        DB::purge('missing_sale_cost_test');
        DB::setDefaultConnection('missing_sale_cost_test');
        Schema::create('app_settings', function (Blueprint $t) {
            $t->id();
            $t->string('key');
            $t->string('value');
            $t->timestamps();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('type');
            $t->string('name');
            $t->softDeletes();
            $t->timestamps();
        });
        Schema::create('employee_details', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->softDeletes();
            $t->timestamps();
        });
        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->string('nameAr');
            $t->string('product_code');
            $t->integer('stock');
            $t->softDeletes();
            $t->timestamps();
        });
        Schema::create('sizes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('itemId');
            $t->string('size');
            $t->timestamps();
        });
        Schema::create('size_colors', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('sizeId');
            $t->string('colorAr');
            $t->integer('stock')->default(0);
            $t->string('meta_catalog_sync_status')->nullable();
            $t->timestamps();
        });
        Schema::create('boxes', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('currency');
            $t->double('total');
            $t->timestamps();
        });
        Schema::create('instant_sales', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('product_id');
            $t->unsignedBigInteger('parent_id')->nullable();
            $t->unsignedBigInteger('size_id')->nullable();
            $t->unsignedBigInteger('size_color_id')->nullable();
            $t->string('serial_number')->nullable();
            $t->string('status')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->boolean('stock_restored')->default(false);
            $t->string('sale_kind')->nullable();
            $t->unsignedBigInteger('sales_order_id')->nullable();
            $t->unsignedBigInteger('maintenance_id')->nullable();
            $t->unsignedBigInteger('payment_box_id')->nullable();
            $t->double('payment_box_value')->default(0);
            $t->double('cost')->default(0);
            $t->double('total_cost')->default(0);
            $t->double('discount')->nullable();
            $t->unsignedBigInteger('offer_package_id')->nullable();
            $t->double('quantity');
            $t->string('inventory_cost_method')->nullable();
            $t->double('inventory_unit_cost')->nullable();
            $t->double('inventory_total_cost')->nullable();
            $t->timestamps();
        });
        Schema::create('inventory_cost_layers', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('product_id');
            $t->unsignedBigInteger('size_id')->nullable();
            $t->unsignedBigInteger('size_color_id')->nullable();
            $t->double('quantity');
            $t->double('remaining_quantity');
            $t->double('unit_cost');
            $t->double('original_unit_cost')->nullable();
            $t->string('currency');
            $t->string('source_type');
            $t->unsignedBigInteger('source_id')->nullable();
            $t->string('idempotency_key')->nullable()->unique();
            $t->timestamp('effective_at')->nullable();
            $t->timestamps();
        });
        Schema::create('inventory_cost_balances', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('product_id');
            $t->unsignedBigInteger('size_id')->nullable();
            $t->unsignedBigInteger('size_color_id')->nullable();
            $t->string('identity_key')->unique();
            $t->double('quantity')->default(0);
            $t->double('inventory_value')->default(0);
            $t->double('moving_average_unit_cost')->default(0);
            $t->string('currency');
            $t->boolean('needs_review')->default(false);
            $t->string('review_reason')->nullable();
            $t->timestamps();
        });
        Schema::create('inventory_cost_allocations', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('product_id');
            $t->unsignedBigInteger('size_id')->nullable();
            $t->unsignedBigInteger('size_color_id')->nullable();
            $t->unsignedBigInteger('inventory_cost_layer_id')->nullable();
            $t->double('quantity');
            $t->double('unit_cost');
            $t->double('total_cost');
            $t->string('method');
            $t->string('reference_type');
            $t->unsignedBigInteger('reference_id');
            $t->timestamps();
        });
        Schema::create('product_stock_movements', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('product_id');
            $t->unsignedBigInteger('size_id')->nullable();
            $t->unsignedBigInteger('size_color_id')->nullable();
            $t->string('type');
            $t->integer('quantity');
            $t->integer('stock_before');
            $t->integer('stock_after');
            $t->double('unit_cost')->nullable();
            $t->double('total_cost')->nullable();
            $t->string('costing_method')->nullable();
            $t->string('reference_type');
            $t->unsignedBigInteger('reference_id');
            $t->string('reason')->nullable();
            $t->text('note')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });
        Schema::create('inventory_adjustments', function (Blueprint $t) {
            $t->id();
            $t->string('reference');
            $t->unsignedBigInteger('product_id');
            $t->unsignedBigInteger('size_id')->nullable();
            $t->unsignedBigInteger('size_color_id')->nullable();
            $t->string('adjustment_type');
            $t->double('stock_before');
            $t->double('stock_after');
            $t->double('quantity_difference');
            $t->double('old_unit_cost')->nullable();
            $t->double('new_unit_cost');
            $t->double('old_value');
            $t->double('new_value');
            $t->double('value_difference');
            $t->string('currency');
            $t->string('costing_method');
            $t->text('reason');
            $t->text('notes')->nullable();
            $t->unsignedBigInteger('created_by');
            $t->timestamps();
        });
        Schema::create('instant_sale_revisions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('instant_sale_id');
            $t->unsignedBigInteger('actor_user_id');
            $t->string('action');
            $t->json('before_snapshot');
            $t->json('after_snapshot');
            $t->string('reason');
            $t->json('metadata');
            $t->timestamps();
        });
        Schema::create('sales_return_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('instant_sale_id');
        });
        $this->actor = new User;
        $this->actor->forceFill(['name' => 'محمود', 'type' => 'admin'])->save();
        $this->product = Product::withoutEvents(fn () => Product::create([
            'id' => 962, 'nameAr' => 'اكس مطور كبير تفصيل', 'product_code' => '000964', 'stock' => 0,
        ]));
        $this->box = Box::create(['name' => 'الصندوق', 'currency' => 'شيكل', 'total' => 1000]);
    }

    protected function tearDown(): void
    {
        DB::purge('missing_sale_cost_test');
        DB::setDefaultConnection($this->previous);
        parent::tearDown();
    }

    private function sale(array $extra = []): InstantSale
    {
        return InstantSale::create(array_merge(['product_id' => 962, 'quantity' => 2,
            'serial_number' => 'SAL-0000123', 'total_cost' => 100, 'cost' => 100,
            'payment_box_id' => $this->box->id, 'payment_box_value' => 100, 'status' => 'active'], $extra));
    }

    private function preview(?int $variant = null, float $cost = 10): array
    {
        return app(MissingSaleCostService::class)->preview($this->actor, 962, $variant, $cost, 'شيكل');
    }

    private function apply(array $ids, string $token, ?int $variant = null): array
    {
        return app(MissingSaleCostService::class)->apply($this->actor, 962, $variant, 10, 'شيكل', $ids, $token, 'تصحيح تكلفة بيع قديم');
    }

    public function test_zero_stock_review_and_apply_create_consumed_layers_only(): void
    {
        $sale = $this->sale();
        $untouched = $this->sale();
        $preview = $this->preview();
        $this->assertCount(2, $preview['sales']);
        $this->assertSame(-20.0, $preview['sales'][0]['profit_change']);
        $this->assertSame(0, InventoryCostLayer::count()); // Review is read-only.
        $result = $this->apply([$sale->id], $preview['review_token']);
        $this->assertSame(1, $result['corrected_count']);
        $this->assertEquals(20, $sale->fresh()->inventory_total_cost);
        $this->assertNull($untouched->fresh()->inventory_total_cost);
        $this->assertEquals(0, $this->product->fresh()->stock);
        $this->assertEquals(1000, $this->box->fresh()->total);
        $this->assertEquals(100, $sale->fresh()->total_cost);
        $this->assertEquals(100, $sale->fresh()->payment_box_value);
        $this->assertEquals(2, $sale->fresh()->quantity);
        $layer = InventoryCostLayer::sole();
        $this->assertSame('manual_sold_cost', $layer->source_type);
        $this->assertEquals(2, $layer->quantity);
        $this->assertEquals(0, $layer->remaining_quantity);
        $this->assertEquals($layer->id, InventoryCostAllocation::sole()->inventory_cost_layer_id);
        $this->assertEquals(0, InventoryAdjustment::sole()->quantity_difference);
        $this->assertEquals(0, ProductStockMovement::sole()->quantity);
        $revision = InstantSaleRevision::sole();
        $this->assertNull($revision->before_snapshot['inventory_total_cost']);
        $this->assertEquals(20, $revision->after_snapshot['inventory_total_cost']);
        $this->assertEquals($this->actor->id, $revision->actor_user_id);
        $this->assertTrue(app(InventoryCostIntegrityService::class)->inspectInstantSale($sale->fresh())['ready']);
    }

    public function test_repeated_confirmation_is_rejected_without_duplicate_layers(): void
    {
        $sale = $this->sale();
        $preview = $this->preview();
        $this->apply([$sale->id], $preview['review_token']);
        try {
            $this->apply([$sale->id], $preview['review_token']);
            $this->fail('Duplicate correction');
        } catch (ValidationException) {
        }
        $this->assertSame(1, InventoryCostLayer::count());
        $this->assertSame(1, InstantSaleRevision::count());
    }

    public function test_legacy_unmarked_zero_sale_keeps_available_stock_layers_unchanged(): void
    {
        DB::table('products')->where('id', $this->product->id)->update(['stock' => 5]);
        $available = InventoryCostLayer::create(['product_id' => 962, 'quantity' => 5,
            'remaining_quantity' => 5, 'unit_cost' => 18, 'currency' => 'شيكل', 'source_type' => 'purchase_receipt_item']);
        $sale = $this->sale(['inventory_total_cost' => 0]);
        $this->apply([$sale->id], $this->preview()['review_token']);
        $this->assertEquals(20, $sale->fresh()->inventory_total_cost);
        $this->assertEquals(5, $this->product->fresh()->stock);
        $this->assertEquals(5, $available->fresh()->remaining_quantity);
        $this->assertEquals(18, $available->fresh()->unit_cost);
        $this->assertEquals(5, InventoryCostLayer::sum('remaining_quantity'));
        $this->assertEquals(0, InventoryAdjustment::sole()->value_difference);
    }

    public function test_legacy_cost_evidence_requires_its_audit_record(): void
    {
        $sale = $this->sale();
        $this->apply([$sale->id], $this->preview()['review_token']);
        InstantSaleRevision::query()->delete();
        $inspection = app(InventoryCostIntegrityService::class)->inspectInstantSale($sale->fresh());
        $this->assertFalse($inspection['ready']);
        $this->assertContains('missing_stock_movement', array_column($inspection['issues'], 'code'));
    }

    public function test_known_positive_and_explicit_zero_costs_are_never_overwritten(): void
    {
        $known = $this->sale(['inventory_cost_method' => 'fifo', 'inventory_unit_cost' => 12, 'inventory_total_cost' => 24]);
        $free = $this->sale(['inventory_cost_method' => 'fifo', 'inventory_unit_cost' => 0, 'inventory_total_cost' => 0]);
        $this->assertSame([], $this->preview()['sales']);
        $this->assertEquals(24, $known->fresh()->inventory_total_cost);
        $this->assertEquals(0, $free->fresh()->inventory_total_cost);
    }

    public function test_partial_allocations_keep_known_cost_and_only_fill_the_gap(): void
    {
        $sale = $this->sale(['quantity' => 3]);
        $layer = InventoryCostLayer::create(['product_id' => 962, 'quantity' => 1, 'remaining_quantity' => 0,
            'unit_cost' => 5, 'currency' => 'شيكل', 'source_type' => 'purchase_receipt_item']);
        $known = InventoryCostAllocation::create(['product_id' => 962, 'quantity' => 1, 'unit_cost' => 5,
            'total_cost' => 5, 'method' => 'fifo', 'inventory_cost_layer_id' => $layer->id,
            'reference_type' => 'instant_sale', 'reference_id' => $sale->id]);
        $pending = InventoryCostAllocation::create(['product_id' => 962, 'quantity' => 2, 'unit_cost' => 0,
            'total_cost' => 0, 'method' => 'fifo', 'reference_type' => 'instant_sale', 'reference_id' => $sale->id]);
        ProductStockMovement::create(['product_id' => 962, 'type' => 'sale', 'quantity' => -3,
            'stock_before' => 3, 'stock_after' => 0, 'reference_type' => 'instant_sale', 'reference_id' => $sale->id]);
        $preview = $this->preview();
        $this->assertSame(2.0, $preview['sales'][0]['missing_quantity']);
        $this->apply([$sale->id], $preview['review_token']);
        $this->assertEquals(25, $sale->fresh()->inventory_total_cost);
        $this->assertEquals(5, $known->fresh()->unit_cost);
        $this->assertEquals(10, $pending->fresh()->unit_cost);
        $this->assertSame(2, InventoryCostAllocation::count());
        $this->assertEquals(2, InventoryCostLayer::where('source_type', 'manual_sold_cost')->sole()->quantity);
        $this->assertTrue(app(InventoryCostIntegrityService::class)->inspectInstantSale($sale->fresh())['ready']);
    }

    public function test_stale_review_and_changed_unit_cost_cannot_apply(): void
    {
        $sale = $this->sale();
        $old = $this->preview(cost: 9);
        try {
            $this->apply([$sale->id], $old['review_token']);
            $this->fail('Changed cost accepted');
        } catch (ValidationException) {
        }
        $preview = $this->preview();
        $sale->update(['quantity' => 3]);
        try {
            $this->apply([$sale->id], $preview['review_token']);
            $this->fail('Stale review accepted');
        } catch (ValidationException) {
        }
        $this->assertSame(0, InventoryCostLayer::count());
    }

    public function test_cancelled_returned_and_linked_sales_remain_blocked(): void
    {
        $cancelled = $this->sale(['status' => 'cancelled']);
        $returned = $this->sale();
        DB::table('sales_return_items')->insert(['instant_sale_id' => $returned->id]);
        $linked = $this->sale(['sales_order_id' => 4]);
        $preview = $this->preview();
        $this->assertCount(3, $preview['sales']);
        $this->assertFalse(collect($preview['sales'])->contains('eligible', true));
        try {
            $this->apply([$cancelled->id, $returned->id, $linked->id], $preview['review_token']);
            $this->fail('Blocked sale changed');
        } catch (ValidationException) {
        }
        $this->assertSame(0, InventoryCostLayer::count());
    }

    public function test_foreign_variant_and_other_product_ids_are_rejected(): void
    {
        $size = Size::create(['id' => 50, 'itemId' => 1, 'size' => 'كبير']);
        $variant = SizeColor::create(['id' => 60, 'sizeId' => $size->id, 'colorAr' => 'أسود']);
        try {
            $this->preview($variant->id);
            $this->fail('Foreign variant');
        } catch (ValidationException) {
        }
        $sale = $this->sale(['product_id' => 1]);
        try {
            $this->apply([$sale->id], $this->preview()['review_token']);
            $this->fail('Foreign sale');
        } catch (ValidationException) {
        }
        $this->assertSame(0, InventoryCostLayer::count());
    }

    public function test_variant_cost_is_separate_and_product_quantity_is_not_changed(): void
    {
        $size = Size::create(['id' => 50, 'itemId' => 962, 'size' => 'كبير']);
        $v1 = SizeColor::create(['id' => 60, 'sizeId' => $size->id, 'colorAr' => 'أسود']);
        $v2 = SizeColor::create(['id' => 61, 'sizeId' => $size->id, 'colorAr' => 'أحمر']);
        $s1 = $this->sale(['size_color_id' => $v1->id, 'size_id' => $size->id]);
        $s2 = $this->sale(['size_color_id' => $v2->id, 'size_id' => $size->id]);
        $preview = $this->preview($v1->id);
        $this->assertCount(1, $preview['sales']);
        $this->apply([$s1->id], $preview['review_token'], $v1->id);
        $this->assertNull($s2->fresh()->inventory_total_cost);
        $this->assertEquals(20, $s1->fresh()->inventory_total_cost);
        $this->assertEquals(0, $v1->fresh()->stock);
        $this->assertEquals(0, $this->product->fresh()->stock);
    }

    public function test_permission_and_currency_are_enforced(): void
    {
        $sale = $this->sale();
        $employee = new User(['type' => 'customer']);
        try {
            app(MissingSaleCostService::class)->preview($employee, 962, null, 10, 'شيكل');
            $this->fail('Unauthorized review');
        } catch (ValidationException) {
        }
        $wrongCurrency = app(MissingSaleCostService::class)->preview($this->actor, 962, null, 10, 'دولار');
        $this->assertFalse($wrongCurrency['sales'][0]['eligible']);
        try {
            app(MissingSaleCostService::class)->apply($this->actor, 962, null, 10, 'دولار', [$sale->id], $wrongCurrency['review_token'], 'سبب');
            $this->fail('Wrong currency');
        } catch (ValidationException) {
        }
        $this->assertSame(0, InventoryAdjustment::count());
    }

    public function test_zero_stock_without_sales_does_not_invent_a_sale_or_quantity(): void
    {
        $this->assertSame([], $this->preview()['sales']);
        $this->assertEquals(0, $this->product->fresh()->stock);
        $this->assertSame(0, InventoryCostLayer::count());
    }

    public function test_posted_invoice_gets_cost_lines_without_cash_or_revenue_changes(): void
    {
        $sale = $this->sale();
        $this->enableLedger();
        $this->apply([$sale->id], $this->preview()['review_token']);
        $entry = \App\Models\AccountingJournalEntry::where('source_type', 'instant_sale')->where('source_id', $sale->id)->sole();
        $lines = $entry->lines()->with('account')->get()->keyBy(fn ($line) => $line->account->system_key);
        $this->assertEquals(100, $lines['cash']->debit);
        $this->assertEquals(100, $lines['sales_revenue']->credit);
        $this->assertEquals(20, $lines['cost_of_goods_sold']->debit);
        $this->assertEquals(20, $lines['inventory']->credit);
        $this->assertEquals($entry->lines->sum('debit'), $entry->lines->sum('credit'));
        $this->assertEquals(1000, $this->box->fresh()->total);
    }

    public function test_closed_accounting_period_rolls_back_cost_and_audit(): void
    {
        $sale = $this->sale();
        $this->enableLedger();
        \App\Models\AccountingPeriod::create(['name' => 'مغلق', 'starts_at' => now()->startOfMonth()->toDateString(),
            'ends_at' => now()->endOfMonth()->toDateString(), 'status' => 'closed']);
        try {
            $this->apply([$sale->id], $this->preview()['review_token']);
            $this->fail('Closed period correction was accepted');
        } catch (ValidationException) {
        }
        $this->assertNull($sale->fresh()->inventory_total_cost);
        $this->assertSame(0, InventoryCostLayer::count());
        $this->assertSame(0, InventoryCostAllocation::count());
        $this->assertSame(0, InventoryAdjustment::count());
        $this->assertSame(0, InstantSaleRevision::count());
    }

    public function test_api_review_and_confirmation_use_separate_steps(): void
    {
        $sale = $this->sale();
        $data = ['product_id' => 962, 'unit_cost' => 10, 'currency' => 'شيكل'];
        $controller = app(\App\Http\Controllers\API\ProductStockController::class);
        $service = app(MissingSaleCostService::class);
        $request = \Illuminate\Http\Request::create('/', 'GET', $data);
        $request->setUserResolver(fn () => $this->actor);
        $preview = $controller->missingSalesCostPreview($request, $service)->getData(true);
        $this->assertSame('success', $preview['status']);
        $this->assertSame(0, InventoryCostLayer::count());
        $request = \Illuminate\Http\Request::create('/', 'POST', array_merge($data, [
            'sale_ids' => [$sale->id], 'review_token' => $preview['result']['review_token'], 'reason' => 'تصحيح موثق',
        ]));
        $request->setUserResolver(fn () => $this->actor);
        $result = $controller->initializeSalesCost($request, $service)->getData(true);
        $this->assertSame('success', $result['status']);
        $this->assertEquals(20, $sale->fresh()->inventory_total_cost);
    }

    public function test_partially_corrected_invoice_also_respects_closed_period(): void
    {
        $sale = $this->sale();
        $child = $this->sale(['parent_id' => $sale->id]);
        $this->enableLedger();
        \App\Models\AccountingPeriod::create(['name' => 'مغلق', 'starts_at' => now()->startOfMonth()->toDateString(),
            'ends_at' => now()->endOfMonth()->toDateString(), 'status' => 'closed']);
        try {
            $this->apply([$sale->id], $this->preview()['review_token']);
            $this->fail('Partial cost correction bypassed the closed period');
        } catch (ValidationException) {
        }
        $this->assertNull($sale->fresh()->inventory_total_cost);
        $this->assertNull($child->fresh()->inventory_total_cost);
        $this->assertSame(0, InventoryAdjustment::count());
        $this->assertSame(0, InventoryCostLayer::count());
    }

    public function test_review_separates_product_selling_price_from_whole_invoice_total(): void
    {
        $root = $this->sale(['cost' => 50, 'quantity' => 2, 'total_cost' => 150, 'discount' => 25]);
        $child = $this->sale(['cost' => 25, 'quantity' => 3, 'total_cost' => 75, 'parent_id' => $root->id]);
        $rows = collect($this->preview()['sales'])->keyBy('id');
        $this->assertEquals(2, $rows[$root->id]['quantity']);
        $this->assertEquals(50, $rows[$root->id]['sale_unit_price']);
        $this->assertEquals(100, $rows[$root->id]['sale_line_total']);
        $this->assertEquals(150, $rows[$root->id]['invoice_total']);
        $this->assertEquals(25, $rows[$root->id]['invoice_discount']);
        $this->assertEquals(25, $rows[$child->id]['sale_unit_price']);
        $this->assertEquals(75, $rows[$child->id]['sale_line_total']);
        $this->assertEquals(150, $rows[$child->id]['invoice_total']);
        $this->assertEquals(25, $rows[$child->id]['invoice_discount']);
    }

    private function reference(float $cost = 280, ?int $variant = null): InventoryAdjustment
    {
        return app(InventoryAdjustmentService::class)->setReferenceCost($this->product, $cost,
            'تسجيل سعر مرجعي', null, 'شيكل', $variant, $this->actor->id);
    }

    public function test_zero_stock_without_sales_can_save_reference_without_inventory_or_ledger_changes(): void
    {
        $this->enableLedger();
        $adjustment = $this->reference();
        $summary = app(InventoryCostingService::class)->productSummary($this->product->fresh());
        $this->assertEquals(280, $summary['reference_unit_cost']);
        $this->assertSame('شيكل', $summary['reference_cost_currency']);
        $this->assertEquals(0, $summary['quantity_on_hand']);
        $this->assertEquals(0, $summary['inventory_value']);
        $this->assertEquals(0, $summary['average_inventory_unit_cost']);
        $this->assertNull($summary['next_fifo_unit_cost']);
        $this->assertSame(0, InventoryCostLayer::count());
        $this->assertSame(0, InventoryCostAllocation::count());
        $this->assertSame(0, InstantSale::count());
        $this->assertSame(0, \App\Models\InventoryCostBalance::count());
        $this->assertSame(0, \App\Models\AccountingJournalEntry::count());
        $this->assertEquals(0, $adjustment->quantity_difference);
        $this->assertEquals(0, $adjustment->value_difference);
        $this->assertEquals(0, ProductStockMovement::sole()->quantity);
        $this->assertEquals($this->actor->id, $adjustment->created_by);
        $this->assertNull(app(InventoryCostingService::class)->productSummary($this->product->fresh(), false)['reference_unit_cost']);
    }

    public function test_reference_updates_preserve_audit_and_do_not_recost_existing_sales(): void
    {
        $sale = $this->sale();
        $this->reference();
        $latest = $this->reference(300);
        $this->assertEquals(280, $latest->old_unit_cost);
        $this->assertEquals(300, $latest->new_unit_cost);
        $this->assertEquals(2, InventoryAdjustment::count());
        $this->assertNull($sale->fresh()->inventory_total_cost);
        $this->assertSame(0, InstantSaleRevision::count());
        $this->assertEquals(300, app(InventoryCostingService::class)->productSummary($this->product->fresh())['reference_unit_cost']);
    }

    public function test_reference_cannot_be_saved_after_quantity_changes_to_positive_or_negative(): void
    {
        foreach ([1, -1] as $quantity) {
            DB::table('products')->where('id', 962)->update(['stock' => $quantity]);
            try {
                $this->reference();
                $this->fail('Reference accepted for nonzero stock');
            } catch (ValidationException) {
            }
        }
        $this->assertSame(0, InventoryAdjustment::count());
    }

    public function test_reference_is_variant_specific_and_rejects_unselected_or_foreign_variants(): void
    {
        $size = Size::create(['id' => 50, 'itemId' => 962, 'size' => 'كبير']);
        $variant = SizeColor::create(['id' => 60, 'sizeId' => $size->id, 'colorAr' => 'أسود']);
        $other = SizeColor::create(['id' => 61, 'sizeId' => $size->id, 'colorAr' => 'أبيض']);
        $this->reference(280, $variant->id);
        $summary = app(InventoryCostingService::class)->productSummary($this->product->fresh());
        $rows = collect($summary['variants'])->keyBy('size_color_id');
        $this->assertEquals(280, $rows[$variant->id]['reference_unit_cost']);
        $this->assertNull($rows[$other->id]['reference_unit_cost']);
        try {
            $this->reference();
            $this->fail('Variant was not selected');
        } catch (ValidationException) {
        }
        Size::create(['id' => 51, 'itemId' => 1, 'size' => 'آخر']);
        SizeColor::create(['id' => 62, 'sizeId' => 51, 'colorAr' => 'أحمر']);
        try {
            $this->reference(280, 62);
            $this->fail('Foreign variant accepted');
        } catch (ValidationException) {
        }
        $this->assertSame(1, InventoryAdjustment::count());
    }

    private function filteredProducts(string $status): array
    {
        $reflection = new \ReflectionClass(\App\Http\Controllers\API\Stocks::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $query = Product::query();
        $reflection->getMethod('applyInventoryCostStatusFilter')->invoke($controller, $query, $status);

        return $query->pluck('id')->all();
    }

    public function test_cost_filter_recognizes_reference_only_while_identity_is_zero(): void
    {
        $this->assertEquals([962], $this->filteredProducts('without'));
        $this->reference();
        $this->assertEquals([], $this->filteredProducts('without'));
        $this->assertEquals([962], $this->filteredProducts('with'));
        DB::table('products')->where('id', 962)->update(['stock' => 1]);
        $this->assertEquals([962], $this->filteredProducts('without'));
        $this->assertEquals([], $this->filteredProducts('with'));
        InventoryCostLayer::create(['product_id' => 962, 'quantity' => 1, 'remaining_quantity' => 1,
            'unit_cost' => 300, 'currency' => 'شيكل', 'source_type' => 'purchase_receipt_item']);
        $this->assertEquals([962], $this->filteredProducts('with'));
    }

    public function test_initialize_api_requires_explicit_reference_mode_for_zero_stock(): void
    {
        $controller = app(\App\Http\Controllers\API\ProductStockController::class);
        $data = ['product_id' => 962, 'unit_cost' => 280, 'currency' => 'شيكل', 'reason' => 'سعر مرجعي'];
        $request = \Illuminate\Http\Request::create('/', 'POST', $data);
        $request->setUserResolver(fn () => $this->actor);
        $this->assertSame('error', $controller->initializeCost($request)->getData(true)['status']);
        $request = \Illuminate\Http\Request::create('/', 'POST', $data + ['reference_only' => true]);
        $request->setUserResolver(fn () => $this->actor);
        $response = $controller->initializeCost($request)->getData(true);
        $this->assertSame('success', $response['status']);
        $this->assertEquals(280, $response['inventory']['reference_unit_cost']);
        $this->assertSame(InventoryAdjustment::TYPE_COST_REFERENCE, InventoryAdjustment::sole()->adjustment_type);
    }

    public function test_variant_reference_filter_does_not_cover_positive_uncosted_stock(): void
    {
        Size::create(['id' => 50, 'itemId' => 962, 'size' => 'كبير']);
        SizeColor::create(['id' => 60, 'sizeId' => 50, 'colorAr' => 'أسود']);
        $this->reference(280, 60);
        $this->assertEquals([962], $this->filteredProducts('with'));
        $this->assertEquals([], $this->filteredProducts('without'));
        DB::table('size_colors')->where('id', 60)->update(['stock' => 2]);
        $this->assertEquals([], $this->filteredProducts('with'));
        $this->assertEquals([962], $this->filteredProducts('without'));
    }

    public function test_package_component_price_is_explicit_in_review(): void
    {
        $root = $this->sale(['offer_package_id' => 10]);
        $child = $this->sale(['cost' => 0, 'parent_id' => $root->id]);
        $rows = collect($this->preview()['sales'])->keyBy('id');
        $this->assertTrue($rows[$child->id]['sale_price_included_in_package']);
        $this->assertEquals(0, $rows[$child->id]['sale_unit_price']);
    }

    private function enableLedger(): void
    {
        // This test owns an in-memory SQLite connection; production is never used.
        $migration = require database_path('migrations/2026_09_19_200000_create_accounting_ledger_tables.php');
        $migration->up();
    }
}
