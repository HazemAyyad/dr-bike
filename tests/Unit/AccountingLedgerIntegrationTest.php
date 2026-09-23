<?php

namespace Tests\Unit;

use App\Models\AccountingJournalEntry;
use App\Models\InstantSale;
use App\Models\ProfitSale;
use App\Models\PurchasePayment;
use App\Models\PurchaseReceipt;
use App\Models\ReturnModel;
use App\Models\SalesOrder;
use App\Models\SalesOrderSettlement;
use App\Models\SalesReturn;
use App\Services\AccountingIntegrityService;
use App\Services\AccountingProjectionRepairService;
use App\Services\AccountingProjectionService;
use App\Services\AccountingReportService;
use App\Services\AccountingService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class AccountingLedgerIntegrationTest extends TestCase
{
    private string $originalConnection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConnection = (string) config('database.default');
        config([
            'database.default' => 'accounting_test',
            'database.connections.accounting_test' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);
        DB::purge('accounting_test');
        DB::setDefaultConnection('accounting_test');
        Schema::connection('accounting_test')->create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
        });
        Schema::connection('accounting_test')->create('assets', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('months_number')->default(1);
            $table->decimal('price', 14, 4)->default(0);
            $table->decimal('depreciation_price', 14, 4)->default(0);
            $table->timestamps();
        });
        Schema::connection('accounting_test')->create('asset_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('asset_id')->nullable();
            $table->string('type')->nullable();
            $table->decimal('depreciation_amount', 14, 4)->default(0);
            $table->string('depreciation_period')->nullable();
            $table->timestamps();
        });
        Schema::connection('accounting_test')->create('expenses', function (Blueprint $table) {
            $table->id();
            $table->decimal('price', 14, 4)->default(0);
            $table->string('payment_method')->nullable();
            $table->string('expense_type')->nullable();
            $table->unsignedBigInteger('box_id')->nullable();
            $table->timestamps();
        });
        Schema::connection('accounting_test')->create('box_logs', function (Blueprint $table) {
            $table->id();
            $table->string('type')->nullable();
            $table->string('description')->nullable();
            $table->timestamps();
        });
        Schema::connection('accounting_test')->create('maintenance', function (Blueprint $table) {
            $table->id();
            $table->string('status')->nullable();
            $table->decimal('invoice_total', 14, 4)->default(0);
            $table->unsignedBigInteger('instant_sale_id')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::connection('accounting_test')->create('maintenance_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('maintenance_id');
            $table->decimal('quantity', 14, 4)->default(0);
            $table->decimal('inventory_total_cost', 14, 4)->nullable();
            $table->timestamps();
        });
        Schema::connection('accounting_test')->create('project_expenses', function (Blueprint $table) {
            $table->id();
            $table->decimal('expenses', 12, 2);
            $table->text('notes')->nullable();
        });
        Schema::connection('accounting_test')->create('outgoing_checks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('status')->default('not_cashed');
            $table->decimal('total', 14, 4)->default(0);
            $table->string('currency')->default('شيكل');
        });
        Schema::connection('accounting_test')->create('boxes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('total', 18, 4)->default(0);
            $table->string('currency')->default('شيكل');
        });
        Schema::connection('accounting_test')->create('sales_orders', function (Blueprint $table) {
            $table->id();
            $table->string('serial_number')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('delivery_company_id')->nullable();
            $table->string('status')->default('unconfirmed');
            $table->boolean('is_debt_collection')->default(false);
            $table->decimal('total', 14, 4)->default(0);
            $table->decimal('payment_amount', 14, 4)->default(0);
            $table->unsignedBigInteger('payment_box_id')->nullable();
            $table->decimal('customer_debt_balance', 14, 4)->default(0);
            $table->decimal('carrier_receivable_balance', 14, 4)->default(0);
            $table->timestamp('financial_posted_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
        Schema::connection('accounting_test')->create('instant_sales', function (Blueprint $table) {
            $table->id();
            $table->string('serial_number')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('size_id')->nullable();
            $table->unsignedBigInteger('size_color_id')->nullable();
            $table->unsignedBigInteger('sales_order_id')->nullable();
            $table->unsignedBigInteger('maintenance_id')->nullable();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->decimal('total_cost', 14, 4)->default(0);
            $table->decimal('cost', 14, 4)->default(0);
            $table->integer('quantity')->default(0);
            $table->string('inventory_cost_method')->nullable();
            $table->decimal('inventory_unit_cost', 14, 6)->nullable();
            $table->decimal('inventory_total_cost', 14, 6)->nullable();
            $table->unsignedBigInteger('buyer_id')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->unsignedBigInteger('payment_box_id')->nullable();
            $table->decimal('payment_box_value', 14, 4)->nullable();
            $table->string('status')->nullable();
            $table->string('sale_kind')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
        Schema::connection('accounting_test')->create('profit_sales', function (Blueprint $table) {
            $table->id();
            $table->decimal('total_cost', 14, 4)->default(0);
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->unsignedBigInteger('payment_box_id')->nullable();
            $table->decimal('payment_box_value', 14, 4)->nullable();
            $table->string('status')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
        Schema::connection('accounting_test')->create('bills', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('currency')->default('شيكل');
            $table->timestamps();
        });
        Schema::connection('accounting_test')->create('purchase_receipts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('bill_id');
            $table->string('receipt_number')->nullable();
            $table->date('received_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
        Schema::connection('accounting_test')->create('purchase_receipt_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('purchase_receipt_id');
            $table->unsignedBigInteger('bill_item_id')->nullable();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('size_id')->nullable();
            $table->unsignedBigInteger('size_color_id')->nullable();
            $table->decimal('accepted_quantity', 14, 4)->default(0);
            $table->decimal('unit_price', 14, 6)->default(0);
            $table->timestamps();
        });
        Schema::connection('accounting_test')->create('purchase_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('bill_id')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('box_id')->nullable();
            $table->decimal('amount', 14, 4)->default(0);
            $table->string('currency')->default('شيكل');
            $table->string('type')->default('payment');
            $table->date('paid_at')->nullable();
            $table->unsignedBigInteger('debt_transaction_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
        Schema::connection('accounting_test')->create('sales_order_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sales_order_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('size_id')->nullable();
            $table->unsignedBigInteger('size_color_id')->nullable();
            $table->integer('quantity')->default(0);
            $table->integer('dispatched_qty')->default(0);
            $table->integer('delivered_qty')->default(0);
            $table->boolean('is_hidden')->default(false);
            $table->timestamps();
        });
        Schema::connection('accounting_test')->create('sales_order_settlements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sales_order_id');
            $table->unsignedBigInteger('box_id')->nullable();
            $table->string('source');
            $table->decimal('amount', 14, 4)->default(0);
            $table->decimal('cash_amount', 14, 4)->nullable();
            $table->decimal('carrier_fee', 14, 4)->default(0);
            $table->decimal('customer_debt_before', 14, 4)->default(0);
            $table->decimal('customer_debt_after', 14, 4)->default(0);
            $table->decimal('carrier_receivable_before', 14, 4)->default(0);
            $table->decimal('carrier_receivable_after', 14, 4)->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
        Schema::connection('accounting_test')->create('sales_returns', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sales_order_id')->nullable();
            $table->string('serial_number')->nullable();
            $table->string('return_type')->default('direct');
            $table->string('status')->default('completed');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->decimal('total_amount', 14, 4)->default(0);
            $table->decimal('cash_refund_amount', 14, 4)->default(0);
            $table->decimal('credit_amount', 14, 4)->default(0);
            $table->decimal('carrier_credit_amount', 14, 4)->default(0);
            $table->unsignedBigInteger('refund_box_id')->nullable();
            $table->string('currency')->default('شيكل');
            $table->unsignedBigInteger('debt_transaction_id')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
        Schema::connection('accounting_test')->create('sales_return_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sales_return_id');
            $table->unsignedBigInteger('product_id');
            $table->integer('quantity')->default(0);
            $table->decimal('inventory_unit_cost', 14, 4)->nullable();
            $table->decimal('inventory_total_cost', 14, 4)->nullable();
            $table->timestamps();
        });
        Schema::connection('accounting_test')->create('returns', function (Blueprint $table) {
            $table->id();
            $table->string('number')->nullable();
            $table->unsignedBigInteger('bill_id')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->decimal('total', 14, 4)->default(0);
            $table->string('currency')->default('شيكل');
            $table->string('status')->default('settled');
            $table->string('resolution')->nullable();
            $table->unsignedBigInteger('refund_box_id')->nullable();
            $table->unsignedBigInteger('debt_transaction_id')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
        Schema::connection('accounting_test')->create('purchase_returns', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('return_id');
            $table->unsignedBigInteger('bill_id')->nullable();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('size_id')->nullable();
            $table->unsignedBigInteger('size_color_id')->nullable();
            $table->decimal('quantity', 14, 4)->default(0);
            $table->decimal('price', 14, 4)->default(0);
            $table->decimal('line_total', 14, 4)->default(0);
            $table->decimal('cost_total', 14, 6)->default(0);
            $table->timestamps();
        });
        Schema::connection('accounting_test')->create('inventory_cost_allocations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('inventory_cost_layer_id')->nullable();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('size_id')->nullable();
            $table->unsignedBigInteger('size_color_id')->nullable();
            $table->decimal('quantity', 14, 4)->default(0);
            $table->decimal('unit_cost', 14, 6)->default(0);
            $table->string('reference_type');
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->decimal('total_cost', 14, 4)->default(0);
            $table->string('method')->default('fifo');
            $table->timestamps();
        });
        Schema::connection('accounting_test')->create('inventory_cost_layers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('size_id')->nullable();
            $table->unsignedBigInteger('size_color_id')->nullable();
            $table->decimal('quantity', 14, 4)->default(0);
            $table->decimal('remaining_quantity', 14, 4)->default(0);
            $table->decimal('unit_cost', 14, 6)->default(0);
            $table->decimal('original_unit_cost', 14, 6)->nullable();
            $table->string('currency')->default('شيكل');
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('idempotency_key')->nullable();
            $table->timestamp('effective_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('accounting_test')->create('product_stock_movements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('size_id')->nullable();
            $table->unsignedBigInteger('size_color_id')->nullable();
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->integer('quantity')->default(0);
            $table->decimal('unit_cost', 14, 4)->nullable();
            $table->decimal('total_cost', 14, 4)->nullable();
            $table->string('costing_method')->nullable();
            $table->timestamps();
        });
        Schema::connection('accounting_test')->create('debt_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('type');
            $table->decimal('amount', 14, 4);
            $table->string('currency')->default('شيكل');
            $table->decimal('balance_after', 14, 4)->default(0);
            $table->string('source')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
        (require database_path('migrations/2026_09_19_200000_create_accounting_ledger_tables.php'))->up();
        (require database_path('migrations/2026_09_19_201000_create_accounting_cutovers_table.php'))->up();
        (require database_path('migrations/2026_09_19_202000_add_accounting_sources_to_assets_and_project_expenses.php'))->up();
        (require database_path('migrations/2026_09_19_203000_add_box_id_to_outgoing_checks_table.php'))->up();
        (require database_path('migrations/2026_09_19_204000_add_carrier_credit_to_sales_returns.php'))->up();
        (require database_path('migrations/2026_09_23_100000_add_service_revenue_account.php'))->up();
    }

    protected function tearDown(): void
    {
        DB::disconnect('accounting_test');
        DB::setDefaultConnection($this->originalConnection);
        config(['database.default' => $this->originalConnection]);
        parent::tearDown();
    }

    public function test_post_is_idempotent_and_reversal_keeps_double_entry_balance(): void
    {
        $service = app(AccountingService::class);
        $entry = $service->post(
            'test:1',
            'test',
            1,
            '2026-09-19',
            'NIS',
            'قيد اختبار',
            [
                ['account_key' => 'cash', 'debit' => 100, 'credit' => 0, 'box_id' => 5],
                ['account_key' => 'opening_balance', 'debit' => 0, 'credit' => 100],
            ],
        );

        $this->assertSame('شيكل', $entry->currency);
        $this->assertCount(2, $entry->lines);

        $updated = $service->post(
            'test:1',
            'test',
            1,
            '2026-09-19',
            'شيكل',
            'قيد اختبار معدل',
            [
                ['account_key' => 'cash', 'debit' => 120, 'credit' => 0, 'box_id' => 5],
                ['account_key' => 'opening_balance', 'debit' => 0, 'credit' => 120],
            ],
        );

        $this->assertSame($entry->id, $updated->id);
        $this->assertSame(1, AccountingJournalEntry::query()->count());
        $this->assertEqualsWithDelta(120, $updated->lines->sum('debit'), 0.0001);
        $this->assertEqualsWithDelta(120, $updated->lines->sum('credit'), 0.0001);

        $reversal = $service->reverse('test', 1, '2026-09-20', 'عكس اختبار');

        $this->assertNotNull($reversal);
        $this->assertSame(AccountingJournalEntry::STATUS_REVERSED, $updated->fresh()->status);
        $this->assertEqualsWithDelta(0, DB::table('accounting_journal_lines')->sum(DB::raw('debit - credit')), 0.0001);
        $this->assertEqualsWithDelta(240, DB::table('accounting_journal_lines')->sum('debit'), 0.0001);
        $this->assertEqualsWithDelta(240, DB::table('accounting_journal_lines')->sum('credit'), 0.0001);
    }

    public function test_accounting_source_and_cutover_migrations_are_additive(): void
    {
        $this->assertTrue(Schema::hasTable('accounting_cutovers'));
        $this->assertTrue(Schema::hasColumn('assets', 'box_id'));
        $this->assertTrue(Schema::hasColumn('assets', 'currency'));
        $this->assertTrue(Schema::hasColumn('assets', 'acquired_at'));
        $this->assertTrue(Schema::hasColumn('project_expenses', 'box_id'));
        $this->assertTrue(Schema::hasColumn('project_expenses', 'currency'));
        $this->assertTrue(Schema::hasColumn('project_expenses', 'expense_date'));
        $this->assertTrue(Schema::hasColumn('project_expenses', 'created_by'));
        $this->assertTrue(Schema::hasColumn('outgoing_checks', 'box_id'));
        $this->assertTrue(Schema::hasColumn('accounting_journal_lines', 'delivery_company_id'));
        $this->assertTrue(Schema::hasColumn('sales_returns', 'carrier_credit_amount'));
        $this->assertTrue(DB::table('accounting_accounts')->where('system_key', 'customer_deposits')->exists());
        $this->assertTrue(DB::table('accounting_accounts')->where('system_key', 'service_revenue')->exists());
    }

    public function test_order_deposit_is_released_into_revenue_without_double_counting_cash(): void
    {
        DB::table('boxes')->insert(['id' => 1, 'name' => 'مبيعات', 'total' => 40, 'currency' => 'شيكل']);
        $order = SalesOrder::query()->create([
            'serial_number' => 'SO-1',
            'customer_id' => 7,
            'delivery_company_id' => 9,
            'status' => 'with_delivery',
            'total' => 100,
            'payment_amount' => 40,
            'payment_box_id' => 1,
        ]);
        $settlement = SalesOrderSettlement::query()->create([
            'sales_order_id' => $order->id,
            'box_id' => 1,
            'source' => 'order_payment',
            'amount' => 40,
            'cash_amount' => 40,
        ]);

        app(AccountingProjectionService::class)->sync($settlement->fresh());
        DB::table('sales_order_items')->insert([
            'sales_order_id' => $order->id,
            'product_id' => 10,
            'quantity' => 1,
            'dispatched_qty' => 1,
            'delivered_qty' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $layerId = DB::table('inventory_cost_layers')->insertGetId([
            'product_id' => 10,
            'quantity' => 1,
            'remaining_quantity' => 0,
            'unit_cost' => 30,
            'original_unit_cost' => 30,
            'currency' => 'شيكل',
            'source_type' => 'purchase_receipt_item',
            'source_id' => 1,
            'effective_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('inventory_cost_allocations')->insert([
            'inventory_cost_layer_id' => $layerId,
            'product_id' => 10,
            'quantity' => 1,
            'unit_cost' => 30,
            'reference_type' => 'sales_order',
            'reference_id' => $order->id,
            'total_cost' => 30,
            'method' => 'fifo',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('product_stock_movements')->insert([
            'product_id' => 10,
            'reference_type' => 'sales_order',
            'reference_id' => $order->id,
            'quantity' => -1,
            'unit_cost' => 30,
            'total_cost' => 30,
            'costing_method' => 'fifo',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $order->update([
            'status' => 'delivered',
            'financial_posted_at' => now(),
            'customer_debt_balance' => 20,
            'carrier_receivable_balance' => 40,
        ]);

        $saleEntry = AccountingJournalEntry::query()
            ->where('source_type', 'sales_order')
            ->where('source_id', $order->id)
            ->whereNull('reverses_entry_id')
            ->first();
        $this->assertNotNull(
            $saleEntry,
            (string) DB::table('accounting_projection_failures')->where('source_type', 'sales_order')->value('error'),
        );
        $saleEntry->load('lines.account');
        $byAccount = $saleEntry->lines->keyBy(fn ($line) => $line->account->system_key);

        $this->assertEqualsWithDelta(40, $byAccount['customer_deposits']->debit, 0.0001);
        $this->assertEqualsWithDelta(60, $saleEntry->lines->where('account.system_key', 'accounts_receivable')->sum('debit'), 0.0001);
        $this->assertEqualsWithDelta(100, $byAccount['sales_revenue']->credit, 0.0001);
        $this->assertEqualsWithDelta(30, $byAccount['cost_of_goods_sold']->debit, 0.0001);
        $this->assertEqualsWithDelta(30, $byAccount['inventory']->credit, 0.0001);
        $this->assertEqualsWithDelta(40, DB::table('accounting_journal_lines as lines')
            ->join('accounting_accounts as accounts', 'accounts.id', '=', 'lines.account_id')
            ->where('accounts.system_key', 'cash')->sum(DB::raw('lines.debit - lines.credit')), 0.0001);

        $order->update(['carrier_receivable_balance' => 0]);
        $saleEntry->refresh()->load('lines.account');
        $carrierLine = $saleEntry->lines->firstWhere('delivery_company_id', 9);
        $this->assertNotNull($carrierLine);
        $this->assertEqualsWithDelta(40, $carrierLine->debit, 0.0001);
    }

    public function test_debt_collection_order_reduces_receivable_instead_of_creating_revenue_or_deposit(): void
    {
        DB::table('boxes')->insert(['id' => 1, 'name' => 'مبيعات', 'total' => 40, 'currency' => 'شيكل']);
        app(AccountingService::class)->post('opening:ar', 'accounting_cutover', 1, now(), 'شيكل', 'ذمة افتتاحية', [
            ['account_key' => 'accounts_receivable', 'debit' => 100, 'credit' => 0, 'customer_id' => 7],
            ['account_key' => 'opening_balance', 'debit' => 0, 'credit' => 100],
        ]);
        $order = SalesOrder::query()->create([
            'serial_number' => 'COL-1',
            'customer_id' => 7,
            'status' => 'with_delivery',
            'is_debt_collection' => true,
            'total' => 40,
            'payment_amount' => 40,
            'payment_box_id' => 1,
        ]);
        $settlement = SalesOrderSettlement::query()->create([
            'sales_order_id' => $order->id,
            'box_id' => 1,
            'source' => 'order_payment',
            'amount' => 40,
            'cash_amount' => 40,
        ]);
        app(AccountingProjectionService::class)->sync($settlement->fresh());

        $entry = AccountingJournalEntry::query()
            ->where('source_type', 'sales_order_settlement')
            ->where('source_id', $settlement->id)
            ->firstOrFail()
            ->load('lines.account');
        $this->assertEqualsWithDelta(40, $entry->lines->firstWhere('account.system_key', 'cash')->debit, 0.0001);
        $this->assertEqualsWithDelta(40, $entry->lines->firstWhere('account.system_key', 'accounts_receivable')->credit, 0.0001);
        $this->assertNull($entry->lines->firstWhere('account.system_key', 'customer_deposits'));
        $this->assertFalse(AccountingJournalEntry::query()->where('source_type', 'sales_order')->where('source_id', $order->id)->exists());
    }

    public function test_financial_statements_reconcile_from_opening_to_current_balance(): void
    {
        DB::table('boxes')->insert([
            'id' => 1,
            'name' => 'الصندوق الرئيسي',
            'total' => 150,
            'currency' => 'شيكل',
        ]);
        DB::table('accounting_cutovers')->insert([
            'cutover_date' => '2026-09-19',
            'status' => 'applied',
            'snapshot' => json_encode(['test' => true]),
            'applied_at' => '2026-09-19 08:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ledger = app(AccountingService::class);
        $ledger->post('opening:test', 'accounting_cutover', 1, '2026-09-19', 'شيكل', 'افتتاحي', [
            ['account_key' => 'cash', 'debit' => 100, 'credit' => 0, 'box_id' => 1],
            ['account_key' => 'opening_balance', 'debit' => 0, 'credit' => 100],
        ]);
        $ledger->post('sale:test', 'instant_sale', 1, '2026-09-20', 'شيكل', 'بيع نقدي', [
            ['account_key' => 'cash', 'debit' => 50, 'credit' => 0, 'box_id' => 1],
            ['account_key' => 'sales_revenue', 'debit' => 0, 'credit' => 50],
        ]);

        $reports = app(AccountingReportService::class);
        $from = Carbon::parse('2026-09-20');
        $to = Carbon::parse('2026-09-30');
        $trial = $reports->trialBalance($from, $to, 'شيكل');
        $income = $reports->incomeStatement($from, $to, 'شيكل');
        $balanceSheet = $reports->balanceSheet($to, 'شيكل');
        $cashFlow = $reports->cashFlow($from, $to, 'شيكل');

        $this->assertTrue($trial['summary']['balanced']);
        $this->assertEqualsWithDelta(150, $trial['summary']['debit'], 0.0001);
        $this->assertEqualsWithDelta(50, $income['summary']['net_profit'], 0.0001);
        $this->assertTrue($balanceSheet['summary']['balanced']);
        $this->assertEqualsWithDelta(150, $balanceSheet['summary']['assets'], 0.0001);
        $this->assertEqualsWithDelta(100, $cashFlow['summary']['opening_cash'], 0.0001);
        $this->assertEqualsWithDelta(150, $cashFlow['summary']['closing_cash'], 0.0001);
        $this->assertTrue($trial['quality']['complete']);
    }

    public function test_report_data_survives_an_auxiliary_quality_check_failure(): void
    {
        app(AccountingService::class)->post('quality:test', 'test', 1, '2026-09-21', 'شيكل', 'اختبار الجودة', [
            ['account_key' => 'cash', 'debit' => 25, 'credit' => 0, 'box_id' => 1],
            ['account_key' => 'owner_equity', 'debit' => 0, 'credit' => 25],
        ]);
        Schema::drop('accounting_projection_failures');

        $report = app(AccountingReportService::class)->trialBalance(
            Carbon::parse('2026-09-01'),
            Carbon::parse('2026-09-30'),
            'شيكل',
        );

        $this->assertNotEmpty($report['rows']);
        $this->assertFalse($report['quality']['complete']);
        $this->assertTrue($report['quality']['quality_check_failed']);
    }

    public function test_cash_product_sale_posts_revenue_fifo_cogs_inventory_and_is_idempotent(): void
    {
        $sale = $this->createProductSale(100, 100, 2, 15);
        $projection = app(AccountingProjectionService::class);

        $first = $projection->syncOrFail($sale);
        $second = $projection->syncOrFail($sale->fresh());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, AccountingJournalEntry::query()->where('source_type', 'instant_sale')->where('source_id', $sale->id)->count());
        $this->assertEntryAccounts($first->fresh('lines.account'), [
            'cash', 'sales_revenue', 'cost_of_goods_sold', 'inventory',
        ]);
        $this->assertJournalBalanced($first->fresh('lines'));
    }

    public function test_credit_product_sale_posts_accounts_receivable_without_cash(): void
    {
        $sale = $this->createProductSale(100, 0, 1, 30);
        $entry = app(AccountingProjectionService::class)->syncOrFail($sale);

        $this->assertEntryAccounts($entry, ['accounts_receivable', 'sales_revenue', 'cost_of_goods_sold', 'inventory']);
        $this->assertFalse($entry->lines->pluck('account.system_key')->contains('cash'));
        $this->assertJournalBalanced($entry);
    }

    public function test_partial_payment_product_sale_splits_cash_and_receivable(): void
    {
        $sale = $this->createProductSale(100, 40, 1, 25);
        $entry = app(AccountingProjectionService::class)->syncOrFail($sale)->load('lines.account');

        $this->assertEqualsWithDelta(40, $entry->lines->firstWhere('account.system_key', 'cash')->debit, 0.0001);
        $this->assertEqualsWithDelta(60, $entry->lines->firstWhere('account.system_key', 'accounts_receivable')->debit, 0.0001);
        $this->assertJournalBalanced($entry);
    }

    public function test_profit_sale_uses_service_revenue_and_never_inventory_or_cogs(): void
    {
        $this->ensureBox();
        $sale = ProfitSale::withoutEvents(fn () => ProfitSale::query()->create([
            'total_cost' => 75,
            'customer_id' => 9,
            'payment_box_id' => 1,
            'payment_box_value' => 75,
            'status' => 'active',
        ]));

        $entry = app(AccountingProjectionService::class)->syncOrFail($sale)->load('lines.account');

        $this->assertEntryAccounts($entry, ['cash', 'service_revenue']);
        $this->assertFalse($entry->lines->pluck('account.system_key')->contains('inventory'));
        $this->assertFalse($entry->lines->pluck('account.system_key')->contains('cost_of_goods_sold'));
        $this->assertJournalBalanced($entry);
    }

    public function test_purchase_receipt_and_supplier_payment_create_balanced_idempotent_journals(): void
    {
        $this->ensureBox();
        DB::table('bills')->insert(['id' => 1, 'seller_id' => 22, 'currency' => 'شيكل', 'created_at' => now(), 'updated_at' => now()]);
        $receipt = PurchaseReceipt::withoutEvents(fn () => PurchaseReceipt::query()->create([
            'bill_id' => 1,
            'receipt_number' => 'REC-1',
            'received_at' => now()->toDateString(),
        ]));
        DB::table('purchase_receipt_items')->insert([
            'purchase_receipt_id' => $receipt->id,
            'product_id' => 44,
            'accepted_quantity' => 3,
            'unit_price' => 20,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $receiptEntry = app(AccountingProjectionService::class)->syncOrFail($receipt->fresh());

        $payment = PurchasePayment::withoutEvents(fn () => PurchasePayment::query()->create([
            'bill_id' => 1,
            'seller_id' => 22,
            'box_id' => 1,
            'amount' => 25,
            'currency' => 'شيكل',
            'type' => 'payment',
            'paid_at' => now()->toDateString(),
        ]));
        $paymentEntry = app(AccountingProjectionService::class)->syncOrFail($payment);
        app(AccountingProjectionService::class)->syncOrFail($payment->fresh());

        $this->assertEntryAccounts($receiptEntry, ['inventory', 'accounts_payable']);
        $this->assertEntryAccounts($paymentEntry, ['accounts_payable', 'cash']);
        $this->assertSame(1, AccountingJournalEntry::query()->where('source_type', 'purchase_payment')->where('source_id', $payment->id)->count());
        $this->assertJournalBalanced($receiptEntry);
        $this->assertJournalBalanced($paymentEntry);
    }

    public function test_missing_fifo_cost_keeps_projection_failure_open_and_creates_no_journal(): void
    {
        $sale = $this->createProductSale(50, 50, 1, 0, snapshot: false, pending: true);

        $entry = app(AccountingProjectionService::class)->sync($sale);

        $this->assertNull($entry);
        $this->assertFalse(AccountingJournalEntry::query()->where('source_type', 'instant_sale')->where('source_id', $sale->id)->exists());
        $failure = DB::table('accounting_projection_failures')->where('source_key', 'instant_sale:'.$sale->id)->first();
        $this->assertNotNull($failure);
        $this->assertNull($failure->resolved_at);
        $this->assertStringContainsString('pending_negative_cost', $failure->error);
        $context = json_decode($failure->context, true);
        $this->assertSame('inventory_cost_integrity', $context['reason']);
        $this->assertSame(RuntimeException::class, $context['exception']);
        $this->assertNotEmpty($context['message']);
        $this->assertNotEmpty($context['failed_at']);
    }

    public function test_zero_revenue_product_sale_with_fifo_cost_requires_review(): void
    {
        $sale = $this->createProductSale(0, 0, 1, 15);

        $entry = app(AccountingProjectionService::class)->sync($sale);

        $this->assertNull($entry);
        $this->assertFalse(AccountingJournalEntry::query()->where('source_type', 'instant_sale')->where('source_id', $sale->id)->exists());
        $failure = DB::table('accounting_projection_failures')->where('source_key', 'instant_sale:'.$sale->id)->first();
        $this->assertNotNull($failure);
        $this->assertNull($failure->resolved_at);
        $this->assertStringContainsString('zero revenue', $failure->error);

        $preview = app(AccountingProjectionRepairService::class)->run(true);
        $this->assertSame(0, $preview['summary']['repairable']);
        $this->assertSame(1, $preview['summary']['still_failing']);
        $this->assertSame('zero_revenue_with_fifo_cost', $preview['items'][0]['issues'][0]['code']);

        $repair = app(AccountingProjectionRepairService::class)->run(false);
        $this->assertSame(1, $repair['summary']['still_failing']);
        $this->assertSame(0, $repair['summary']['successfully_repaired']);
        $failure = DB::table('accounting_projection_failures')->where('source_key', 'instant_sale:'.$sale->id)->first();
        $context = json_decode($failure->context, true);
        $this->assertNull($failure->resolved_at);
        $this->assertSame('still_failing', $context['repair_attempt']['status']);
        $this->assertStringContainsString('zero revenue', $context['repair_attempt']['message']);
    }

    public function test_failed_repair_rolls_back_fifo_snapshot_changes(): void
    {
        $sale = $this->createProductSale(50, 50, 1, 15, snapshot: false);
        app(AccountingProjectionService::class)->sync($sale);
        DB::table('accounting_accounts')->where('system_key', 'sales_revenue')->update(['is_active' => false]);

        $result = app(AccountingProjectionRepairService::class)->run(false);

        $this->assertSame(1, $result['summary']['still_failing']);
        $this->assertNull($sale->fresh()->inventory_total_cost);
        $this->assertFalse(AccountingJournalEntry::query()
            ->where('source_type', 'instant_sale')
            ->where('source_id', $sale->id)
            ->exists());
        $this->assertNull(DB::table('accounting_projection_failures')
            ->where('source_key', 'instant_sale:'.$sale->id)
            ->value('resolved_at'));
    }

    public function test_projection_repair_rebuilds_only_reliable_snapshot_and_remains_idempotent(): void
    {
        $sale = $this->createProductSale(80, 80, 2, 12, snapshot: false);
        app(AccountingProjectionService::class)->sync($sale);
        $this->assertNull($sale->fresh()->inventory_total_cost);

        $writeQueries = [];
        DB::listen(function ($query) use (&$writeQueries) {
            if (preg_match('/^\s*(insert|update|delete|replace|alter|create|drop|truncate)\b/i', $query->sql)) {
                $writeQueries[] = $query->sql;
            }
        });
        $dryRun = app(AccountingProjectionRepairService::class)->run(true);
        $this->assertSame(1, $dryRun['summary']['repairable']);
        $this->assertSame([], $writeQueries, 'Dry-run must not execute mutating SQL, even inside a rolled-back transaction.');
        $this->assertNull($sale->fresh()->inventory_total_cost);
        $this->assertFalse(AccountingJournalEntry::query()->where('source_type', 'instant_sale')->where('source_id', $sale->id)->exists());

        $first = app(AccountingProjectionRepairService::class)->run(false);
        $second = app(AccountingProjectionRepairService::class)->run(false);

        $this->assertSame(1, $first['summary']['successfully_repaired']);
        $this->assertSame(0, $second['summary']['total_failures']);
        $this->assertEqualsWithDelta(24, (float) $sale->fresh()->inventory_total_cost, 0.0001);
        $this->assertSame(1, AccountingJournalEntry::query()->where('source_type', 'instant_sale')->where('source_id', $sale->id)->count());
        $this->assertNotNull(DB::table('accounting_projection_failures')->where('source_key', 'instant_sale:'.$sale->id)->value('resolved_at'));
        $context = json_decode((string) DB::table('accounting_projection_failures')->where('source_key', 'instant_sale:'.$sale->id)->value('context'), true);
        $this->assertSame('repaired', $context['repair']['status']);
        $this->assertNotEmpty($context['repair']['resolved_at']);
    }

    public function test_stale_failure_is_resolved_when_valid_journal_already_exists(): void
    {
        $sale = $this->createProductSale(60, 60, 1, 10);
        app(AccountingProjectionService::class)->syncOrFail($sale);
        DB::table('accounting_projection_failures')->insert([
            'source_key' => 'instant_sale:'.$sale->id,
            'source_type' => 'instant_sale',
            'source_id' => $sale->id,
            'error' => 'old transient failure',
            'attempts' => 1,
            'last_failed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = app(AccountingProjectionRepairService::class)->run(false);

        $this->assertSame(1, $result['summary']['already_valid_or_stale']);
        $this->assertNotNull(DB::table('accounting_projection_failures')->where('source_key', 'instant_sale:'.$sale->id)->value('resolved_at'));
        $this->assertSame(1, AccountingJournalEntry::query()->where('source_type', 'instant_sale')->where('source_id', $sale->id)->count());
        $context = json_decode((string) DB::table('accounting_projection_failures')->where('source_key', 'instant_sale:'.$sale->id)->value('context'), true);
        $this->assertSame('journal_already_valid', $context['repair']['status']);
    }

    public function test_purchase_return_uses_verified_fifo_cost_and_is_idempotent(): void
    {
        $return = ReturnModel::withoutEvents(fn () => ReturnModel::query()->create([
            'number' => 'PRT-1',
            'seller_id' => 33,
            'total' => 20,
            'currency' => 'شيكل',
            'status' => 'settled',
            'resolution' => 'supplier_credit',
            'delivered_at' => now(),
            'settled_at' => now(),
        ]));
        DB::table('purchase_returns')->insert([
            'return_id' => $return->id,
            'product_id' => 71,
            'quantity' => 2,
            'price' => 10,
            'line_total' => 20,
            'cost_total' => 20,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $debtTransactionId = DB::table('debt_transactions')->insertGetId([
            'seller_id' => 33,
            'type' => 'given',
            'amount' => 20,
            'currency' => 'شيكل',
            'balance_after' => 0,
            'source' => 'purchase_return',
            'source_id' => $return->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $return->updateQuietly(['debt_transaction_id' => $debtTransactionId]);
        $layerId = $this->createLayer(71, 2, 10);
        $this->createAllocation($layerId, 71, 2, 10, 'purchase_return', $return->id);
        $this->createMovement(71, -2, 20, 'purchase_return', $return->id);

        $entry = app(AccountingProjectionService::class)->syncOrFail($return->fresh());
        app(AccountingProjectionService::class)->syncOrFail($return->fresh());

        $this->assertEntryAccounts($entry, ['inventory', 'accounts_payable']);
        $this->assertSame(1, AccountingJournalEntry::query()->where('source_type', 'purchase_return')->where('source_id', $return->id)->count());
        $this->assertJournalBalanced($entry);
    }

    public function test_sales_return_reverses_revenue_and_original_fifo_cost_without_duplicates(): void
    {
        $this->ensureBox();
        $return = SalesReturn::withoutEvents(fn () => SalesReturn::query()->create([
            'serial_number' => 'SRT-1',
            'return_type' => 'direct',
            'status' => 'completed',
            'customer_id' => 7,
            'total_amount' => 40,
            'cash_refund_amount' => 40,
            'credit_amount' => 0,
            'refund_box_id' => 1,
            'currency' => 'شيكل',
            'completed_at' => now(),
        ]));
        DB::table('sales_return_items')->insert([
            'sales_return_id' => $return->id,
            'product_id' => 81,
            'quantity' => 2,
            'inventory_unit_cost' => 6,
            'inventory_total_cost' => 12,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $entry = app(AccountingProjectionService::class)->syncOrFail($return->fresh());
        app(AccountingProjectionService::class)->syncOrFail($return->fresh());

        $this->assertEntryAccounts($entry, ['sales_returns', 'cash', 'inventory', 'cost_of_goods_sold']);
        $this->assertSame(1, AccountingJournalEntry::query()->where('source_type', 'sales_return')->where('source_id', $return->id)->count());
        $this->assertJournalBalanced($entry);
    }

    public function test_integrity_service_and_command_report_unbalanced_journal_ids(): void
    {
        $entry = app(AccountingService::class)->post(
            'integrity-test:1',
            'integrity_test',
            1,
            now(),
            'شيكل',
            'قيد فحص السلامة',
            [
                ['account_key' => 'cash', 'debit' => 25, 'credit' => 0],
                ['account_key' => 'other_revenue', 'debit' => 0, 'credit' => 25],
            ],
        );

        $balanced = app(AccountingIntegrityService::class)->run(now()->startOfDay(), now()->endOfDay());
        $this->assertSame('PASS', collect($balanced['checks'])->firstWhere('name', 'journal_balance')['status']);

        $creditLineId = DB::table('accounting_journal_lines')
            ->where('journal_entry_id', $entry->id)
            ->orderByDesc('credit')
            ->value('id');
        DB::table('accounting_journal_lines')->where('id', $creditLineId)->update(['credit' => 24]);
        $this->assertEqualsWithDelta(25, (float) DB::table('accounting_journal_lines')->where('journal_entry_id', $entry->id)->sum('debit'), 0.0001);
        $this->assertEqualsWithDelta(24, (float) DB::table('accounting_journal_lines')->where('journal_entry_id', $entry->id)->sum('credit'), 0.0001);
        $broken = app(AccountingIntegrityService::class)->run(now()->startOfDay(), now()->endOfDay());
        $journalCheck = collect($broken['checks'])->firstWhere('name', 'journal_balance');
        $this->assertSame('ERROR', $journalCheck['status']);
        $this->assertContains($entry->id, $journalCheck['ids']);

        $this->artisan('accounting:check-integrity', [
            '--from' => now()->toDateString(),
            '--to' => now()->toDateString(),
        ])->assertExitCode(1);
    }

    private function createProductSale(
        float $total,
        float $paid,
        int $quantity,
        float $unitCost,
        bool $snapshot = true,
        bool $pending = false,
    ): InstantSale {
        $this->ensureBox();
        $sale = InstantSale::withoutEvents(fn () => InstantSale::query()->create([
            'serial_number' => 'SAL-T'.random_int(1000, 9999),
            'product_id' => 100,
            'quantity' => $quantity,
            'cost' => $total / max(1, $quantity),
            'total_cost' => $total,
            'inventory_cost_method' => 'fifo',
            'inventory_unit_cost' => $snapshot ? $unitCost : null,
            'inventory_total_cost' => $snapshot ? $unitCost * $quantity : null,
            'buyer_id' => 7,
            'payment_box_id' => $paid > 0 ? 1 : null,
            'payment_box_value' => $paid,
            'status' => 'active',
            'sale_kind' => 'regular',
        ]));
        $layerId = $pending ? null : $this->createLayer(100, $quantity, $unitCost);
        $this->createAllocation($layerId, 100, $quantity, $unitCost, 'instant_sale', $sale->id);
        $this->createMovement(100, -$quantity, $pending ? null : $unitCost * $quantity, 'instant_sale', $sale->id);

        return $sale->fresh();
    }

    private function createLayer(int $productId, float $quantity, float $unitCost): int
    {
        return (int) DB::table('inventory_cost_layers')->insertGetId([
            'product_id' => $productId,
            'quantity' => $quantity,
            'remaining_quantity' => 0,
            'unit_cost' => $unitCost,
            'original_unit_cost' => $unitCost,
            'currency' => 'شيكل',
            'source_type' => 'purchase_receipt_item',
            'source_id' => 1,
            'effective_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createAllocation(?int $layerId, int $productId, float $quantity, float $unitCost, string $type, int $id): void
    {
        DB::table('inventory_cost_allocations')->insert([
            'inventory_cost_layer_id' => $layerId,
            'product_id' => $productId,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'total_cost' => $quantity * $unitCost,
            'method' => 'fifo',
            'reference_type' => $type,
            'reference_id' => $id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createMovement(int $productId, int $quantity, ?float $totalCost, string $type, int $id): void
    {
        DB::table('product_stock_movements')->insert([
            'product_id' => $productId,
            'reference_type' => $type,
            'reference_id' => $id,
            'quantity' => $quantity,
            'unit_cost' => $totalCost !== null && $quantity !== 0 ? abs($totalCost / $quantity) : null,
            'total_cost' => $totalCost,
            'costing_method' => 'fifo',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function ensureBox(): void
    {
        DB::table('boxes')->updateOrInsert(['id' => 1], [
            'name' => 'Test box',
            'total' => 1000,
            'currency' => 'شيكل',
        ]);
    }

    private function assertEntryAccounts(AccountingJournalEntry $entry, array $expected): void
    {
        $entry->loadMissing('lines.account');
        $accounts = $entry->lines->pluck('account.system_key');
        foreach ($expected as $account) {
            $this->assertTrue($accounts->contains($account), 'Missing journal account: '.$account);
        }
    }

    private function assertJournalBalanced(AccountingJournalEntry $entry): void
    {
        $entry->loadMissing('lines');
        $this->assertEqualsWithDelta((float) $entry->lines->sum('debit'), (float) $entry->lines->sum('credit'), 0.0001);
    }
}
