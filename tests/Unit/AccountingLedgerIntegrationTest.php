<?php

namespace Tests\Unit;

use App\Models\AccountingJournalEntry;
use App\Models\SalesOrder;
use App\Models\SalesOrderSettlement;
use App\Services\AccountingProjectionService;
use App\Services\AccountingReportService;
use App\Services\AccountingService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
            $table->unsignedBigInteger('sales_order_id')->nullable();
            $table->unsignedBigInteger('parent_id')->nullable();
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
            $table->decimal('credit_amount', 14, 4)->default(0);
            $table->timestamps();
        });
        Schema::connection('accounting_test')->create('inventory_cost_allocations', function (Blueprint $table) {
            $table->id();
            $table->string('reference_type');
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->decimal('total_cost', 14, 4)->default(0);
            $table->timestamps();
        });
        Schema::connection('accounting_test')->create('product_stock_movements', function (Blueprint $table) {
            $table->id();
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->integer('quantity')->default(0);
            $table->decimal('total_cost', 14, 4)->nullable();
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
        DB::table('inventory_cost_allocations')->insert([
            'reference_type' => 'sales_order',
            'reference_id' => $order->id,
            'total_cost' => 30,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('product_stock_movements')->insert([
            'reference_type' => 'sales_order',
            'reference_id' => $order->id,
            'quantity' => -1,
            'total_cost' => 30,
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
}
