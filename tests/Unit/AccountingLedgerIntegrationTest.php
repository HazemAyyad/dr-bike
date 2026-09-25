<?php

namespace Tests\Unit;

use App\Models\AccountingJournalEntry;
use App\Models\Asset;
use App\Models\AssetLog;
use App\Models\Box;
use App\Models\BoxLog;
use App\Models\Customer;
use App\Models\DebtTransaction;
use App\Models\EmployeeDetail;
use App\Models\InstantSale;
use App\Models\Maintenance;
use App\Models\MaintenancePayment;
use App\Models\OutgoingCheck;
use App\Models\ProfitSale;
use App\Models\PurchasePayment;
use App\Models\PurchaseReceipt;
use App\Models\ReturnModel;
use App\Models\SalesOrder;
use App\Models\SalesOrderSettlement;
use App\Models\SalesReturn;
use App\Models\Seller;
use App\Models\User;
use App\Services\AccountingIntegrityService;
use App\Services\AccountingProjectionRepairService;
use App\Services\AccountingProjectionService;
use App\Services\AccountingReconciliationService;
use App\Services\AccountingReportService;
use App\Services\AccountingService;
use App\Services\AssetDepreciationCalculator;
use App\Services\BoxAccessService;
use App\Services\DebtLedgerBalanceRepairService;
use App\Services\DebtLedgerService;
use App\Services\MonthlyAssetDepreciationService;
use App\Services\PurchasePaymentSourceIdentityService;
use App\Services\PurchasingService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Mockery\MockInterface;
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
            $table->string('type')->default('admin');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::connection('accounting_test')->create('logs', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('type')->nullable();
            $table->boolean('is_canceled')->default(false);
            $table->timestamps();
        });
        Schema::connection('accounting_test')->create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->boolean('is_canceled')->default(false);
            $table->timestamps();
        });
        Schema::connection('accounting_test')->create('sellers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->boolean('is_canceled')->default(false);
            $table->timestamps();
        });
        Schema::connection('accounting_test')->create('assets', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('months_number')->default(1);
            $table->decimal('price', 14, 4)->default(0);
            $table->decimal('depreciation_rate', 14, 8)->default(0);
            $table->decimal('depreciation_price', 14, 4)->default(0);
            $table->json('media')->nullable();
            $table->timestamps();
        });
        Schema::connection('accounting_test')->create('asset_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('asset_id')->nullable();
            $table->string('type')->nullable();
            $table->decimal('total', 14, 4)->default(0);
            $table->decimal('value_before', 14, 4)->nullable();
            $table->decimal('depreciation_amount', 14, 4)->default(0);
            $table->string('depreciation_period')->nullable();
            $table->unsignedBigInteger('processed_by_user_id')->nullable();
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
            $table->text('note')->nullable();
            $table->decimal('value', 14, 4)->default(0);
            $table->unsignedBigInteger('box_id')->nullable();
            $table->unsignedBigInteger('from_box_id')->nullable();
            $table->unsignedBigInteger('to_box_id')->nullable();
            $table->timestamps();
        });
        Schema::connection('accounting_test')->create('maintenance', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('status')->nullable();
            $table->decimal('invoice_total', 14, 4)->default(0);
            $table->decimal('paid_amount', 14, 4)->default(0);
            $table->unsignedBigInteger('instant_sale_id')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::connection('accounting_test')->create('maintenance_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('maintenance_id');
            $table->unsignedBigInteger('maintenance_daily_session_id')->nullable();
            $table->unsignedBigInteger('box_id')->nullable();
            $table->unsignedBigInteger('instant_sale_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('method', 32)->default('cash');
            $table->decimal('amount', 14, 4)->default(0);
            $table->string('currency', 32)->default('شيكل');
            $table->text('note')->nullable();
            $table->timestamps();
        });
        Schema::connection('accounting_test')->create('maintenance_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('maintenance_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('size_id')->nullable();
            $table->unsignedBigInteger('size_color_id')->nullable();
            $table->decimal('quantity', 14, 4)->default(0);
            $table->string('inventory_cost_method')->nullable();
            $table->decimal('inventory_unit_cost', 14, 6)->nullable();
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
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('status')->default('not_cashed');
            $table->decimal('total', 14, 4)->default(0);
            $table->date('due_date')->nullable();
            $table->string('currency')->default('شيكل');
            $table->string('check_id')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('img')->nullable();
            $table->string('back_image')->nullable();
            $table->unsignedBigInteger('box_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::connection('accounting_test')->create('incoming_checks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('from_customer')->nullable();
            $table->unsignedBigInteger('from_seller')->nullable();
            $table->unsignedBigInteger('to_customer')->nullable();
            $table->unsignedBigInteger('to_seller')->nullable();
            $table->decimal('total', 14, 4)->default(0);
            $table->date('due_date')->nullable();
            $table->string('currency')->default('شيكل');
            $table->string('check_id')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('front_image')->nullable();
            $table->string('back_image')->nullable();
            $table->string('status')->default('not_cashed');
            $table->text('notes')->nullable();
            $table->date('received_at')->nullable();
            $table->string('batch_number')->nullable();
            $table->timestamps();
        });
        Schema::connection('accounting_test')->create('boxes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('total', 18, 4)->default(0);
            $table->string('currency')->default('شيكل');
            $table->string('type')->nullable();
            $table->boolean('is_shown')->default(true);
            $table->timestamps();
        });
        Schema::connection('accounting_test')->create('employee_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::connection('accounting_test')->create('employee_visible_boxes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('box_id');
            $table->timestamps();
            $table->unique(['employee_id', 'box_id']);
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
            $table->decimal('total', 14, 4)->default(0);
            $table->decimal('final_total', 14, 4)->default(0);
            $table->decimal('paid_amount', 14, 4)->default(0);
            $table->string('status')->default('complete');
            $table->string('workflow_status')->nullable();
            $table->string('payment_status')->default('unpaid');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamps();
        });
        Schema::connection('accounting_test')->create('bill_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('bill_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('size_id')->nullable();
            $table->unsignedBigInteger('size_color_id')->nullable();
            $table->decimal('quantity', 14, 4)->default(0);
            $table->decimal('ordered_quantity', 14, 4)->default(0);
            $table->decimal('received_owned_quantity', 14, 4)->default(0);
            $table->decimal('custody_quantity', 14, 4)->default(0);
            $table->decimal('damaged_quantity', 14, 4)->default(0);
            $table->decimal('mismatched_quantity', 14, 4)->default(0);
            $table->decimal('price', 14, 4)->default(0);
            $table->decimal('final_unit_price', 14, 4)->default(0);
            $table->string('status')->nullable();
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
            $table->text('note')->nullable();
            $table->unsignedBigInteger('debt_transaction_id')->nullable();
            $table->unsignedBigInteger('box_log_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
        Schema::connection('accounting_test')->create('purchase_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('bill_id')->nullable();
            $table->string('event');
            $table->string('title');
            $table->text('description')->nullable();
            $table->json('before_values')->nullable();
            $table->json('after_values')->nullable();
            $table->json('meta')->nullable();
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
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
            $table->text('note')->nullable();
            $table->json('receipt_images')->nullable();
            $table->date('transaction_date')->nullable();
            $table->unsignedBigInteger('box_id')->nullable();
            $table->string('source')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
        (require database_path('migrations/2026_05_20_100000_create_debt_ledger_activity_logs_table.php'))->up();
        (require database_path('migrations/2026_09_19_200000_create_accounting_ledger_tables.php'))->up();
        (require database_path('migrations/2026_09_19_201000_create_accounting_cutovers_table.php'))->up();
        (require database_path('migrations/2026_09_19_202000_add_accounting_sources_to_assets_and_project_expenses.php'))->up();
        (require database_path('migrations/2026_09_19_203000_add_box_id_to_outgoing_checks_table.php'))->up();
        (require database_path('migrations/2026_09_19_204000_add_carrier_credit_to_sales_returns.php'))->up();
        (require database_path('migrations/2026_09_23_100000_add_service_revenue_account.php'))->up();
        (require database_path('migrations/2026_09_24_100000_add_payment_stage_to_maintenance_payments.php'))->up();
        (require database_path('migrations/2026_09_24_100000_add_reason_code_to_box_logs.php'))->up();
        (require database_path('migrations/2026_09_24_100100_add_cash_difference_accounts.php'))->up();
    }

    protected function tearDown(): void
    {
        DB::disconnect('accounting_test');
        DB::setDefaultConnection($this->originalConnection);
        config(['database.default' => $this->originalConnection]);
        Carbon::setTestNow();
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
        $this->assertTrue(Schema::hasColumn('maintenance_payments', 'payment_stage'));
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

    public function test_maintenance_prepayment_is_released_at_delivery_without_double_counting_cash(): void
    {
        $this->ensureBox();
        $maintenance = Maintenance::withoutEvents(fn () => Maintenance::query()->create([
            'customer_id' => 7,
            'status' => 'in_progress',
            'invoice_total' => 1000,
            'paid_amount' => 300,
        ]));
        $prepayment = MaintenancePayment::withoutEvents(fn () => MaintenancePayment::query()->create([
            'maintenance_id' => $maintenance->id,
            'box_id' => 1,
            'payment_stage' => MaintenancePayment::STAGE_PRE_DELIVERY,
            'method' => 'cash',
            'amount' => 300,
            'currency' => 'شيكل',
        ]));

        $depositEntry = app(AccountingProjectionService::class)->syncOrFail($prepayment)->load('lines.account');
        $this->assertEqualsWithDelta(300, $depositEntry->lines->firstWhere('account.system_key', 'cash')->debit, 0.0001);
        $this->assertEqualsWithDelta(300, $depositEntry->lines->firstWhere('account.system_key', 'customer_deposits')->credit, 0.0001);

        $sale = InstantSale::withoutEvents(fn () => InstantSale::query()->create([
            'maintenance_id' => $maintenance->id,
            'total_cost' => 1000,
            'quantity' => 1,
            'buyer_id' => 7,
            'payment_box_id' => 1,
            'payment_box_value' => 500,
            'status' => 'active',
        ]));
        MaintenancePayment::withoutEvents(fn () => MaintenancePayment::query()->create([
            'maintenance_id' => $maintenance->id,
            'box_id' => 1,
            'instant_sale_id' => $sale->id,
            'payment_stage' => MaintenancePayment::STAGE_DELIVERY,
            'method' => 'cash',
            'amount' => 200,
            'currency' => 'شيكل',
        ]));
        $prepayment->updateQuietly(['instant_sale_id' => $sale->id]);
        $maintenance->updateQuietly([
            'status' => 'delivered',
            'paid_amount' => 500,
            'instant_sale_id' => $sale->id,
        ]);

        $invoiceEntry = app(AccountingProjectionService::class)->syncOrFail($sale->fresh())->load('lines.account');
        $byAccount = $invoiceEntry->lines->groupBy(fn ($line) => $line->account->system_key);
        $this->assertEqualsWithDelta(300, $byAccount['customer_deposits']->sum('debit'), 0.0001);
        $this->assertEqualsWithDelta(200, $byAccount['cash']->sum('debit'), 0.0001);
        $this->assertEqualsWithDelta(500, $byAccount['accounts_receivable']->sum('debit'), 0.0001);
        $this->assertEqualsWithDelta(1000, $byAccount['maintenance_revenue']->sum('credit'), 0.0001);
        $this->assertJournalBalanced($invoiceEntry);

        app(AccountingProjectionService::class)->syncOrFail($prepayment->fresh());
        app(AccountingProjectionService::class)->syncOrFail($sale->fresh());
        $this->assertSame(1, AccountingJournalEntry::query()
            ->where('source_type', 'maintenance_payment')
            ->where('source_id', $prepayment->id)
            ->count());
        $this->assertSame(1, AccountingJournalEntry::query()
            ->where('source_type', 'instant_sale')
            ->where('source_id', $sale->id)
            ->count());
        $this->assertEqualsWithDelta(500, DB::table('accounting_journal_lines as lines')
            ->join('accounting_accounts as accounts', 'accounts.id', '=', 'lines.account_id')
            ->where('accounts.system_key', 'cash')
            ->sum(DB::raw('lines.debit - lines.credit')), 0.0001);
    }

    public function test_maintenance_delivery_supports_full_prepayment_full_cash_and_full_debt(): void
    {
        $this->ensureBox();
        foreach ([
            ['prepaid' => 1000, 'delivery' => 0, 'receivable' => 0],
            ['prepaid' => 0, 'delivery' => 1000, 'receivable' => 0],
            ['prepaid' => 0, 'delivery' => 0, 'receivable' => 1000],
        ] as $index => $scenario) {
            $maintenance = Maintenance::withoutEvents(fn () => Maintenance::query()->create([
                'customer_id' => 100 + $index,
                'status' => 'in_progress',
                'invoice_total' => 1000,
                'paid_amount' => $scenario['prepaid'],
            ]));
            if ($scenario['prepaid'] > 0) {
                $prepayment = MaintenancePayment::withoutEvents(fn () => MaintenancePayment::query()->create([
                    'maintenance_id' => $maintenance->id,
                    'box_id' => 1,
                    'payment_stage' => MaintenancePayment::STAGE_PRE_DELIVERY,
                    'amount' => $scenario['prepaid'],
                    'currency' => 'شيكل',
                ]));
                app(AccountingProjectionService::class)->syncOrFail($prepayment);
            }
            $sale = InstantSale::withoutEvents(fn () => InstantSale::query()->create([
                'maintenance_id' => $maintenance->id,
                'total_cost' => 1000,
                'quantity' => 1,
                'buyer_id' => 100 + $index,
                'payment_box_id' => 1,
                'payment_box_value' => $scenario['prepaid'] + $scenario['delivery'],
                'status' => 'active',
            ]));
            if ($scenario['delivery'] > 0) {
                MaintenancePayment::withoutEvents(fn () => MaintenancePayment::query()->create([
                    'maintenance_id' => $maintenance->id,
                    'box_id' => 1,
                    'instant_sale_id' => $sale->id,
                    'payment_stage' => MaintenancePayment::STAGE_DELIVERY,
                    'amount' => $scenario['delivery'],
                    'currency' => 'شيكل',
                ]));
            }
            $maintenance->updateQuietly(['status' => 'delivered', 'instant_sale_id' => $sale->id]);

            $entry = app(AccountingProjectionService::class)->syncOrFail($sale->fresh())->load('lines.account');
            $deposits = $entry->lines->where('account.system_key', 'customer_deposits')->sum('debit');
            $cash = $entry->lines->where('account.system_key', 'cash')->sum('debit');
            $receivable = $entry->lines->where('account.system_key', 'accounts_receivable')->sum('debit');
            $this->assertEqualsWithDelta($scenario['prepaid'], $deposits, 0.0001);
            $this->assertEqualsWithDelta($scenario['delivery'], $cash, 0.0001);
            $this->assertEqualsWithDelta($scenario['receivable'], $receivable, 0.0001);
            $this->assertJournalBalanced($entry);
        }
    }

    public function test_maintenance_prepayment_with_fifo_parts_posts_cogs_and_inventory(): void
    {
        $this->ensureBox();
        $maintenance = Maintenance::withoutEvents(fn () => Maintenance::query()->create([
            'customer_id' => 17,
            'status' => 'in_progress',
            'invoice_total' => 100,
            'paid_amount' => 30,
        ]));
        $prepayment = MaintenancePayment::withoutEvents(fn () => MaintenancePayment::query()->create([
            'maintenance_id' => $maintenance->id,
            'box_id' => 1,
            'payment_stage' => MaintenancePayment::STAGE_PRE_DELIVERY,
            'amount' => 30,
            'currency' => 'شيكل',
        ]));
        app(AccountingProjectionService::class)->syncOrFail($prepayment);
        $sale = $this->createProductSale(100, 30, 1, 25);
        $sale->updateQuietly(['maintenance_id' => $maintenance->id, 'buyer_id' => 17]);
        DB::table('inventory_cost_allocations')
            ->where('reference_type', 'instant_sale')
            ->where('reference_id', $sale->id)
            ->update(['reference_type' => 'maintenance', 'reference_id' => $maintenance->id]);
        DB::table('product_stock_movements')
            ->where('reference_type', 'instant_sale')
            ->where('reference_id', $sale->id)
            ->update(['reference_type' => 'maintenance', 'reference_id' => $maintenance->id]);
        DB::table('maintenance_products')->insert([
            'maintenance_id' => $maintenance->id,
            'product_id' => $sale->product_id,
            'quantity' => 1,
            'inventory_cost_method' => 'fifo',
            'inventory_unit_cost' => 25,
            'inventory_total_cost' => 25,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $maintenance->updateQuietly(['status' => 'delivered', 'instant_sale_id' => $sale->id]);

        $entry = app(AccountingProjectionService::class)->syncOrFail($sale->fresh())->load('lines.account');
        $this->assertEntryAccounts($entry, [
            'customer_deposits', 'accounts_receivable', 'maintenance_revenue',
            'cost_of_goods_sold', 'inventory',
        ]);
        $this->assertFalse($entry->lines->pluck('account.system_key')->contains('cash'));
        $this->assertJournalBalanced($entry);
    }

    public function test_maintenance_deposit_reconciliation_matches_before_and_after_delivery(): void
    {
        DB::table('boxes')->insert(['id' => 1, 'name' => 'صيانة', 'total' => 300, 'currency' => 'شيكل']);
        $maintenance = Maintenance::withoutEvents(fn () => Maintenance::query()->create([
            'customer_id' => 27,
            'status' => 'in_progress',
            'invoice_total' => 1000,
            'paid_amount' => 300,
        ]));
        $prepayment = MaintenancePayment::withoutEvents(fn () => MaintenancePayment::query()->create([
            'maintenance_id' => $maintenance->id,
            'box_id' => 1,
            'payment_stage' => MaintenancePayment::STAGE_PRE_DELIVERY,
            'amount' => 300,
            'currency' => 'شيكل',
        ]));
        app(AccountingProjectionService::class)->syncOrFail($prepayment);

        $before = collect(app(AccountingReconciliationService::class)->reconcile()['comparisons'])
            ->firstWhere('scope', 'customer_deposits');
        $this->assertTrue($before['matches']);
        $this->assertEqualsWithDelta(300, $before['operational_balance'], 0.0001);

        $sale = InstantSale::withoutEvents(fn () => InstantSale::query()->create([
            'maintenance_id' => $maintenance->id,
            'total_cost' => 1000,
            'quantity' => 1,
            'buyer_id' => 27,
            'payment_box_id' => 1,
            'payment_box_value' => 300,
            'status' => 'active',
        ]));
        $prepayment->updateQuietly(['instant_sale_id' => $sale->id]);
        $maintenance->updateQuietly(['status' => 'delivered', 'instant_sale_id' => $sale->id]);
        app(AccountingProjectionService::class)->syncOrFail($sale->fresh());

        $after = collect(app(AccountingReconciliationService::class)->reconcile()['comparisons'])
            ->firstWhere('scope', 'customer_deposits');
        $this->assertTrue($after['matches']);
        $this->assertEqualsWithDelta(0, $after['operational_balance'], 0.0001);
        $this->assertEqualsWithDelta(0, $after['ledger_balance'], 0.0001);
    }

    public function test_maintenance_prepayment_sync_command_is_dry_run_safe_and_idempotent(): void
    {
        $this->ensureBox();
        $maintenance = Maintenance::withoutEvents(fn () => Maintenance::query()->create([
            'customer_id' => 37,
            'status' => 'in_progress',
            'invoice_total' => 200,
            'paid_amount' => 50,
        ]));
        $payment = MaintenancePayment::withoutEvents(fn () => MaintenancePayment::query()->create([
            'maintenance_id' => $maintenance->id,
            'box_id' => 1,
            'payment_stage' => MaintenancePayment::STAGE_PRE_DELIVERY,
            'amount' => 50,
            'currency' => 'شيكل',
        ]));

        $this->assertSame(0, Artisan::call('accounting:sync-maintenance-prepayments', ['--dry-run' => true]));
        $this->assertFalse(AccountingJournalEntry::query()
            ->where('source_type', 'maintenance_payment')
            ->where('source_id', $payment->id)
            ->exists());

        $this->assertSame(0, Artisan::call('accounting:sync-maintenance-prepayments'));
        $this->assertSame(0, Artisan::call('accounting:sync-maintenance-prepayments'));
        $this->assertSame(1, AccountingJournalEntry::query()
            ->where('source_type', 'maintenance_payment')
            ->where('source_id', $payment->id)
            ->count());
    }

    public function test_maintenance_prepayment_reversal_offsets_cash_and_customer_deposit(): void
    {
        $this->ensureBox();
        $maintenance = Maintenance::withoutEvents(fn () => Maintenance::query()->create([
            'customer_id' => 47,
            'status' => 'ongoing',
            'invoice_total' => 100,
            'paid_amount' => 100,
        ]));
        foreach ([100, -100] as $amount) {
            $payment = MaintenancePayment::withoutEvents(fn () => MaintenancePayment::query()->create([
                'maintenance_id' => $maintenance->id,
                'box_id' => 1,
                'payment_stage' => MaintenancePayment::STAGE_PRE_DELIVERY,
                'method' => $amount > 0 ? 'cash' : 'cancellation_reversal',
                'amount' => $amount,
                'currency' => 'شيكل',
            ]));
            app(AccountingProjectionService::class)->syncOrFail($payment);
        }

        foreach (['cash', 'customer_deposits'] as $account) {
            $balance = DB::table('accounting_journal_lines as lines')
                ->join('accounting_accounts as accounts', 'accounts.id', '=', 'lines.account_id')
                ->where('accounts.system_key', $account)
                ->sum(DB::raw('lines.debit - lines.credit'));
            $this->assertEqualsWithDelta(0, $balance, 0.0001);
        }
    }

    public function test_asset_edit_rejects_price_change_without_touching_box_asset_or_journal(): void
    {
        [$asset, $entry] = $this->createAccountingAsset();
        $boxBefore = (float) DB::table('boxes')->where('id', 1)->value('total');
        $entryBefore = $entry->fresh('lines')->toArray();

        $response = $this->withoutMiddleware()->postJson('/api/edit/asset', [
            'asset_id' => $asset->id,
            'name' => $asset->name,
            'price' => 12000,
            'notes' => $asset->notes,
            'months_number' => 100,
            'acquired_at' => '2026-01-01',
            'media' => [],
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('errors.price.0', 'لا يمكن تعديل تكلفة الأصل بعد تسجيله. يجب استخدام عملية تعديل تكلفة أصل مستقلة.');
        $this->assertEqualsWithDelta(10000, (float) $asset->fresh()->price, 0.0001);
        $this->assertEqualsWithDelta($boxBefore, (float) DB::table('boxes')->where('id', 1)->value('total'), 0.0001);
        $this->assertSame($entryBefore, $entry->fresh('lines')->toArray());
    }

    public function test_asset_edit_rejects_acquisition_date_change_after_depreciation(): void
    {
        [$asset, $entry] = $this->createAccountingAsset();
        AssetLog::withoutEvents(fn () => AssetLog::query()->create([
            'asset_id' => $asset->id,
            'type' => 'depreciate',
            'total' => 9900,
            'value_before' => 10000,
            'depreciation_amount' => 100,
            'depreciation_period' => '2026-02',
        ]));
        $entryDate = $entry->entry_date->toDateString();

        $response = $this->withoutMiddleware()->postJson('/api/edit/asset', [
            'asset_id' => $asset->id,
            'name' => $asset->name,
            'price' => 10000,
            'notes' => $asset->notes,
            'months_number' => 100,
            'acquired_at' => '2026-02-01',
            'media' => [],
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('errors.acquired_at.0', 'لا يمكن تغيير تاريخ اقتناء أصل بدأ استخدامه محاسبيًا. استخدم إجراء تصحيح محاسبي مستقل.');
        $this->assertSame('2026-01-01', $asset->fresh()->acquired_at?->toDateString());
        $this->assertSame($entryDate, $entry->fresh()->entry_date->toDateString());
    }

    public function test_legacy_asset_edit_without_acquisition_date_keeps_it_null(): void
    {
        [$asset] = $this->createAccountingAsset(['acquired_at' => null]);

        $this->withoutMiddleware()->postJson('/api/edit/asset', [
            'asset_id' => $asset->id,
            'name' => 'أصل Legacy معدل',
            'price' => 10000,
            'notes' => 'بدون تاريخ مؤكد',
            'months_number' => 100,
            'media' => [],
        ])->assertOk()->assertJsonPath('status', 'success');

        $this->assertNull($asset->fresh()->acquired_at);

        $this->withoutMiddleware()->postJson('/api/edit/asset', [
            'asset_id' => $asset->id,
            'name' => 'أصل Legacy معدل',
            'price' => 10000,
            'notes' => 'بدون تاريخ مؤكد',
            'months_number' => 100,
            'acquired_at' => '2026-01-01',
            'media' => [],
        ])->assertOk()
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('errors.acquired_at.0', 'لا يمكن تغيير تاريخ اقتناء أصل بدأ استخدامه محاسبيًا. استخدم إجراء تصحيح محاسبي مستقل.');
        $this->assertNull($asset->fresh()->acquired_at);
    }

    public function test_non_financial_asset_edit_does_not_rewrite_purchase_journal(): void
    {
        [$asset, $entry] = $this->createAccountingAsset(['media' => ['old.jpg']]);
        $entryBefore = $entry->fresh('lines');
        $lineIdsBefore = $entryBefore->lines->pluck('id')->all();
        $postedAtBefore = $entryBefore->posted_at?->toDateTimeString();
        $boxBefore = $entryBefore->lines->firstWhere('credit', '>', 0)?->box_id;

        $this->withoutMiddleware()->postJson('/api/edit/asset', [
            'asset_id' => $asset->id,
            'name' => 'اسم وصفي جديد',
            'price' => 10000,
            'notes' => 'ملاحظات جديدة',
            'months_number' => 100,
            'acquired_at' => '2026-01-01',
            'media' => [],
        ])->assertOk()->assertJsonPath('status', 'success');

        $asset->refresh();
        $entry->refresh()->load('lines');
        $this->assertSame('اسم وصفي جديد', $asset->name);
        $this->assertSame('ملاحظات جديدة', $asset->notes);
        $this->assertSame([], $asset->media);
        $this->assertEqualsWithDelta(10000, (float) $asset->price, 0.0001);
        $this->assertSame('2026-01-01', $asset->acquired_at?->toDateString());
        $this->assertSame('2026-01-01', $entry->entry_date->toDateString());
        $this->assertSame('شيكل', $entry->currency);
        $this->assertSame($postedAtBefore, $entry->posted_at?->toDateTimeString());
        $this->assertSame($lineIdsBefore, $entry->lines->pluck('id')->all());
        $this->assertSame($boxBefore, $entry->lines->firstWhere('credit', '>', 0)?->box_id);
        $this->assertEqualsWithDelta(10000, (float) $entry->lines->sum('debit'), 0.0001);
        $this->assertEqualsWithDelta(10000, (float) $entry->lines->sum('credit'), 0.0001);
    }

    public function test_bulk_depreciation_uses_post_and_get_is_not_allowed(): void
    {
        Carbon::setTestNow('2026-09-24 12:00:00');
        [$asset] = $this->createAccountingAsset([
            'price' => 1200,
            'depreciation_price' => 1200,
            'months_number' => 12,
        ]);

        $this->withoutMiddleware()->postJson('/api/depreciate/all/assets')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('result.processed', 1);
        $this->assertTrue(AssetLog::query()
            ->where('asset_id', $asset->id)
            ->where('depreciation_period', '2026-09')
            ->exists());
        $this->getJson('/api/depreciate/all/assets')->assertStatus(405);
    }

    public function test_backdated_depreciation_after_newer_period_requires_review_without_writes(): void
    {
        $asset = Asset::withoutEvents(fn () => Asset::query()->create([
            'name' => 'أصل بفترات أحدث',
            'price' => 10000,
            'depreciation_price' => 8000,
            'depreciation_rate' => 0.01,
            'months_number' => 100,
            'currency' => 'شيكل',
            'acquired_at' => '2026-01-01',
            'media' => [],
        ]));
        foreach (['2026-09', '2026-10'] as $period) {
            AssetLog::withoutEvents(fn () => AssetLog::query()->create([
                'asset_id' => $asset->id,
                'type' => 'depreciate',
                'total' => $period === '2026-09' ? 9000 : 8000,
                'depreciation_amount' => 1000,
                'depreciation_period' => $period,
            ]));
        }
        $logsBefore = AssetLog::query()->count();
        $journalsBefore = AccountingJournalEntry::query()->count();

        $calculation = app(AssetDepreciationCalculator::class)->calculate($asset->fresh(), '2026-08');
        $result = app(MonthlyAssetDepreciationService::class)->run('2026-08', 15, $asset->id);

        $this->assertSame('requires_review', $calculation['status']);
        $this->assertSame('يوجد إهلاك منفذ لفترة أحدث؛ لا يمكن تنفيذ إهلاك رجعي تلقائيًا.', $calculation['warning']);
        $this->assertSame(0, $result['processed']);
        $this->assertSame($logsBefore, AssetLog::query()->count());
        $this->assertSame($journalsBefore, AccountingJournalEntry::query()->count());
        $this->assertEqualsWithDelta(8000, (float) $asset->fresh()->depreciation_price, 0.0001);
    }

    public function test_existing_requested_depreciation_period_is_reported_as_already_depreciated(): void
    {
        $asset = Asset::withoutEvents(fn () => Asset::query()->create([
            'name' => 'أصل مهلك لنفس الشهر',
            'price' => 10000,
            'depreciation_price' => 9000,
            'depreciation_rate' => 0.01,
            'months_number' => 100,
            'currency' => 'شيكل',
            'acquired_at' => '2026-01-01',
            'media' => [],
        ]));
        AssetLog::withoutEvents(fn () => AssetLog::query()->create([
            'asset_id' => $asset->id,
            'type' => 'depreciate',
            'total' => 9000,
            'depreciation_amount' => 1000,
            'depreciation_period' => '2026-09',
        ]));

        $calculation = app(AssetDepreciationCalculator::class)->calculate($asset, '2026-09');

        $this->assertSame('already_depreciated', $calculation['status']);
        $this->assertFalse($calculation['eligible']);
    }

    public function test_depreciation_log_and_journal_keep_processed_user(): void
    {
        DB::table('users')->insert(['id' => 15, 'name' => 'Accounting operator']);
        [$asset] = $this->createAccountingAsset([
            'price' => 1200,
            'depreciation_price' => 1200,
            'months_number' => 12,
        ]);

        $result = app(MonthlyAssetDepreciationService::class)->run('2026-09', 15, $asset->id);
        $log = AssetLog::query()->where('asset_id', $asset->id)->where('type', 'depreciate')->firstOrFail();
        $journal = AccountingJournalEntry::query()
            ->where('source_type', 'asset_depreciation')
            ->where('source_id', $log->id)
            ->firstOrFail();

        $this->assertSame(1, $result['processed']);
        $this->assertSame(15, (int) $log->processed_by_user_id);
        $this->assertSame(15, (int) $journal->created_by);
    }

    public function test_straight_line_depreciation_reaches_zero_after_useful_life(): void
    {
        $asset = Asset::withoutEvents(fn () => Asset::query()->create([
            'name' => 'أصل 12 شهر',
            'price' => 12000,
            'depreciation_price' => 12000,
            'depreciation_rate' => 1 / 12,
            'months_number' => 12,
            'currency' => 'شيكل',
            'acquired_at' => '2026-01-01',
        ]));
        $service = app(MonthlyAssetDepreciationService::class);
        foreach (range(1, 12) as $month) {
            $result = $service->run(sprintf('2026-%02d', $month), null, $asset->id);
            $this->assertSame(1, $result['processed']);
        }

        $logs = AssetLog::query()->where('asset_id', $asset->id)->where('type', 'depreciate')->get();
        $this->assertCount(12, $logs);
        $this->assertEqualsWithDelta(12000, $logs->sum('depreciation_amount'), 0.0001);
        $this->assertEqualsWithDelta(0, (float) $asset->fresh()->depreciation_price, 0.0001);
        $this->assertTrue($logs->every(fn ($log) => abs((float) $log->depreciation_amount - 1000) < 0.0001));
    }

    public function test_straight_line_rounding_has_no_residual_and_preview_matches_actual(): void
    {
        $asset = Asset::withoutEvents(fn () => Asset::query()->create([
            'name' => 'أصل 3 أشهر',
            'price' => 10000,
            'depreciation_price' => 10000,
            'depreciation_rate' => 1 / 3,
            'months_number' => 3,
            'currency' => 'شيكل',
            'acquired_at' => '2026-01-01',
        ]));
        $calculator = app(AssetDepreciationCalculator::class);
        $preview = $calculator->calculate($asset, '2026-01');
        app(MonthlyAssetDepreciationService::class)->run('2026-01', null, $asset->id);
        $firstLog = AssetLog::query()->where('asset_id', $asset->id)->where('type', 'depreciate')->firstOrFail();
        $this->assertEqualsWithDelta($preview['next_depreciation_amount'], (float) $firstLog->depreciation_amount, 0.0001);
        $journal = AccountingJournalEntry::query()
            ->where('source_type', 'asset_depreciation')
            ->where('source_id', $firstLog->id)
            ->firstOrFail()
            ->load('lines.account');
        $this->assertEqualsWithDelta(
            (float) $firstLog->depreciation_amount,
            (float) $journal->lines->firstWhere('account.system_key', 'depreciation_expense')->debit,
            0.0001,
        );
        $this->assertEqualsWithDelta(
            (float) $firstLog->depreciation_amount,
            (float) $journal->lines->firstWhere('account.system_key', 'accumulated_depreciation')->credit,
            0.0001,
        );

        app(MonthlyAssetDepreciationService::class)->run('2026-02', null, $asset->id);
        app(MonthlyAssetDepreciationService::class)->run('2026-03', null, $asset->id);
        $this->assertEqualsWithDelta(0, (float) $asset->fresh()->depreciation_price, 0.0001);
        $this->assertEqualsWithDelta(10000, (float) AssetLog::query()
            ->where('asset_id', $asset->id)
            ->where('type', 'depreciate')
            ->sum('depreciation_amount'), 0.0001);
    }

    public function test_depreciation_is_idempotent_and_not_allowed_before_acquisition_month(): void
    {
        $asset = Asset::withoutEvents(fn () => Asset::query()->create([
            'name' => 'أصل مستقبلي',
            'price' => 1200,
            'depreciation_price' => 1200,
            'depreciation_rate' => 1 / 12,
            'months_number' => 12,
            'currency' => 'شيكل',
            'acquired_at' => '2026-10-01',
        ]));
        $service = app(MonthlyAssetDepreciationService::class);
        $before = $service->run('2026-09', null, $asset->id);
        $first = $service->run('2026-10', null, $asset->id);
        $duplicate = $service->run('2026-10', null, $asset->id);

        $this->assertSame(0, $before['processed']);
        $this->assertSame(1, $first['processed']);
        $this->assertSame(0, $duplicate['processed']);
        $this->assertSame(1, AssetLog::query()->where('asset_id', $asset->id)->where('type', 'depreciate')->count());
        $this->assertSame(1, AccountingJournalEntry::query()
            ->where('source_type', 'asset_depreciation')
            ->count());
    }

    public function test_legacy_asset_uses_current_book_value_over_remaining_periods_without_rewriting_history(): void
    {
        $asset = Asset::withoutEvents(fn () => Asset::query()->create([
            'name' => 'أصل قديم',
            'price' => 10000,
            'depreciation_price' => 9000,
            'depreciation_rate' => 0.01,
            'months_number' => 100,
            'currency' => 'شيكل',
            'acquired_at' => '2026-01-01',
        ]));
        foreach (range(1, 5) as $month) {
            AssetLog::withoutEvents(fn () => AssetLog::query()->create([
                'asset_id' => $asset->id,
                'type' => 'depreciate',
                'total' => 9000,
                'value_before' => 10000,
                'depreciation_amount' => 200,
                'depreciation_period' => sprintf('2026-%02d', $month),
            ]));
        }

        $calculation = app(AssetDepreciationCalculator::class)->calculate($asset->fresh(), '2026-06');
        $this->assertSame(5, $calculation['used_periods']);
        $this->assertSame(95, $calculation['remaining_periods']);
        $this->assertEqualsWithDelta(round(9000 / 95, 2), $calculation['next_depreciation_amount'], 0.0001);
        $this->assertSame(5, AssetLog::query()->where('asset_id', $asset->id)->count());
    }

    public function test_legacy_zero_effect_depreciation_logs_do_not_consume_useful_life(): void
    {
        $asset = Asset::withoutEvents(fn () => Asset::query()->create([
            'name' => 'أصل بسجلات قديمة بلا أثر',
            'price' => 90000,
            'depreciation_price' => 90000,
            'depreciation_rate' => 1 / 240,
            'months_number' => 240,
            'currency' => 'شيكل',
            'acquired_at' => '2026-01-01',
        ]));
        AssetLog::withoutEvents(fn () => AssetLog::query()->create([
            'asset_id' => $asset->id,
            'type' => 'create',
            'total' => 90000,
        ]));
        foreach (range(1, 3) as $month) {
            AssetLog::withoutEvents(fn () => AssetLog::query()->create([
                'asset_id' => $asset->id,
                'type' => 'depreciate',
                'total' => 90000,
                'depreciation_period' => sprintf('2026-%02d', $month),
            ]));
        }

        $calculation = app(AssetDepreciationCalculator::class)->calculate($asset->fresh(), '2026-04');
        $this->assertSame(0, $calculation['used_periods']);
        $this->assertSame(240, $calculation['remaining_periods']);
        $this->assertEqualsWithDelta(375, $calculation['next_depreciation_amount'], 0.0001);
        $this->assertSame(4, AssetLog::query()->where('asset_id', $asset->id)->count());
    }

    public function test_exhausted_legacy_asset_with_book_value_returns_warning_without_silent_write(): void
    {
        $asset = Asset::withoutEvents(fn () => Asset::query()->create([
            'name' => 'أصل يحتاج مراجعة',
            'price' => 1000,
            'depreciation_price' => 100,
            'depreciation_rate' => 0.5,
            'months_number' => 2,
            'currency' => 'شيكل',
            'acquired_at' => '2026-01-01',
        ]));
        foreach (['2026-01', '2026-02'] as $period) {
            AssetLog::withoutEvents(fn () => AssetLog::query()->create([
                'asset_id' => $asset->id,
                'type' => 'depreciate',
                'total' => 100,
                'depreciation_amount' => 450,
                'depreciation_period' => $period,
            ]));
        }

        $result = app(MonthlyAssetDepreciationService::class)->run('2026-03', null, $asset->id);
        $this->assertSame(0, $result['processed']);
        $this->assertNotEmpty($result['warnings']);
        $this->assertEqualsWithDelta(100, (float) $asset->fresh()->depreciation_price, 0.0001);
        $this->assertSame(2, AssetLog::query()->where('asset_id', $asset->id)->count());
    }

    /** @return array{0:Asset,1:AccountingJournalEntry} */
    private function createAccountingAsset(array $overrides = []): array
    {
        $this->ensureBox();
        $attributes = array_merge([
            'name' => 'أصل محاسبي',
            'notes' => 'ملاحظة أصلية',
            'price' => 10000,
            'depreciation_price' => 10000,
            'depreciation_rate' => 0.01,
            'months_number' => 100,
            'box_id' => 1,
            'currency' => 'شيكل',
            'acquired_at' => '2026-01-01',
            'media' => [],
        ], $overrides);
        $asset = Asset::withoutEvents(fn () => Asset::query()->create($attributes));
        $entry = app(AccountingProjectionService::class)->syncOrFail($asset);

        $this->assertInstanceOf(AccountingJournalEntry::class, $entry);

        return [$asset->fresh(), $entry];
    }

    public function test_manual_debt_cash_is_atomic_backdated_balances_recalculate_and_source_rows_are_protected(): void
    {
        $customer = Customer::query()->create(['name' => 'Ledger customer', 'phone' => '0599000011']);
        $box = Box::query()->create(['name' => 'Ledger box', 'total' => 1000, 'currency' => 'شيكل']);
        $ledger = app(DebtLedgerService::class);
        $payload = fn (string $type, float $amount, string $date) => [
            'customer_id' => $customer->id,
            'type' => $type,
            'amount' => $amount,
            'currency' => 'دولار',
            'transaction_date' => $date,
            'box_id' => $box->id,
            'source' => 'manual',
        ];

        $first = $ledger->createTransaction($payload('taken', 100, '2026-09-01'), null, logActivity: false);
        $last = $ledger->createTransaction($payload('given', 40, '2026-09-10'), null, logActivity: false);
        $middle = $ledger->createTransaction($payload('taken', 20, '2026-09-05'), null, logActivity: false);

        $this->assertSame('شيكل', $middle->currency);
        $this->assertEqualsWithDelta(100, (float) $first->fresh()->balance_after, 0.0001);
        $this->assertEqualsWithDelta(120, (float) $middle->fresh()->balance_after, 0.0001);
        $this->assertEqualsWithDelta(80, (float) $last->fresh()->balance_after, 0.0001);
        $this->assertEqualsWithDelta(1080, (float) $box->fresh()->total, 0.0001);

        $beforeTransactions = DebtTransaction::query()->count();
        $beforeLogs = BoxLog::query()->count();
        try {
            $ledger->createTransaction($payload('given', 2000, '2026-09-12'), null, logActivity: false);
            $this->fail('Insufficient cash must fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('رصيد الصندوق غير كافٍ لتنفيذ الحركة.', $exception->getMessage());
        }
        $this->assertSame($beforeTransactions, DebtTransaction::query()->count());
        $this->assertSame($beforeLogs, BoxLog::query()->count());
        $this->assertEqualsWithDelta(1080, (float) $box->fresh()->total, 0.0001);

        $source = DebtTransaction::query()->create([
            'customer_id' => $customer->id,
            'type' => 'given',
            'amount' => 50,
            'currency' => 'شيكل',
            'balance_after' => 30,
            'transaction_date' => '2026-09-15',
            'source' => 'instant_sale',
            'source_id' => 77,
        ]);
        $this->expectException(\App\Exceptions\SourceLinkedDebtTransactionException::class);
        $ledger->archiveTransaction($source, logActivity: false);
    }

    public function test_box_adjustment_reason_accounts_and_transfer_projection_are_idempotent(): void
    {
        $from = Box::query()->create(['name' => 'From', 'total' => 1000, 'currency' => 'شيكل']);
        $to = Box::query()->create(['name' => 'To', 'total' => 200, 'currency' => 'شيكل']);
        $projection = app(AccountingProjectionService::class);
        $reasons = [
            'owner_contribution' => 'owner_equity',
            'owner_withdrawal' => 'owner_equity',
            'cash_overage' => 'cash_overage_income',
            'cash_shortage' => 'cash_shortage_expense',
            'accounting_correction' => 'clearing',
        ];

        foreach ($reasons as $reason => $counterAccount) {
            $log = BoxLog::query()->create([
                'box_id' => $from->id,
                'description' => str_contains($reason, 'withdrawal') || str_contains($reason, 'shortage') ? 'تم سحب رصيد من الصندوق' : 'تم اضافة رصيد للصندوق',
                'type' => str_contains($reason, 'withdrawal') || str_contains($reason, 'shortage') ? 'minus' : 'add',
                'value' => 25,
                'reason_code' => $reason,
                'created_by' => null,
            ]);
            $projection->sync($log);
            $projection->sync($log->fresh());
            $entry = AccountingJournalEntry::query()->where('source_type', 'box_adjustment')->where('source_id', $log->id)->firstOrFail();
            $this->assertSame(1, AccountingJournalEntry::query()->where('source_key', 'box_adjustment:'.$log->id)->count());
            $this->assertEntryAccounts($entry, ['cash', $counterAccount]);
            $this->assertJournalBalanced($entry);
        }

        $transfer = BoxLog::query()->create([
            'from_box_id' => $from->id,
            'to_box_id' => $to->id,
            'description' => 'تم نقل رصيد للصندوق',
            'type' => 'transfer',
            'value' => 300,
            'reason_code' => 'box_transfer',
            'created_by' => null,
        ]);
        $projection->sync($transfer);
        $projection->sync($transfer->fresh());
        $entry = AccountingJournalEntry::query()->where('source_type', 'box_transfer')->where('source_id', $transfer->id)->firstOrFail();
        $this->assertSame(1, AccountingJournalEntry::query()->where('source_key', 'box_transfer:'.$transfer->id)->count());
        $this->assertJournalBalanced($entry);
        $this->assertEqualsWithDelta(300, (float) $entry->lines()->where('box_id', $to->id)->value('debit'), 0.0001);
        $this->assertEqualsWithDelta(300, (float) $entry->lines()->where('box_id', $from->id)->value('credit'), 0.0001);
    }

    public function test_reconciliation_is_per_customer_supplier_and_delivery_company(): void
    {
        DB::table('debt_transactions')->insert([
            ['customer_id' => 10, 'seller_id' => null, 'type' => 'given', 'amount' => 100, 'currency' => 'شيكل', 'balance_after' => -100, 'transaction_date' => '2026-09-01', 'created_at' => now(), 'updated_at' => now()],
            ['customer_id' => 20, 'seller_id' => null, 'type' => 'given', 'amount' => 100, 'currency' => 'شيكل', 'balance_after' => -100, 'transaction_date' => '2026-09-01', 'created_at' => now(), 'updated_at' => now()],
            ['customer_id' => null, 'seller_id' => 30, 'type' => 'taken', 'amount' => 70, 'currency' => 'شيكل', 'balance_after' => 70, 'transaction_date' => '2026-09-01', 'created_at' => now(), 'updated_at' => now()],
        ]);
        app(AccountingService::class)->post('party:test', 'test', 501, '2026-09-01', 'شيكل', 'party test', [
            ['account_key' => 'accounts_receivable', 'debit' => 200, 'credit' => 0, 'customer_id' => 20],
            ['account_key' => 'opening_balance', 'debit' => 0, 'credit' => 200],
        ]);
        DB::table('sales_orders')->insert([
            'serial_number' => 'C-1',
            'delivery_company_id' => 40,
            'carrier_receivable_balance' => 55,
            'status' => 'delivered',
            'is_debt_collection' => 0,
            'total' => 55,
            'payment_amount' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $comparisons = collect(app(AccountingReconciliationService::class)->reconcile()['comparisons']);
        $customers = $comparisons->where('scope', 'accounts_receivable_customer')->whereIn('dimension_id', [10, 20]);
        $this->assertCount(2, $customers);
        $this->assertEqualsCanonicalizing([-100.0, 100.0], $customers->pluck('difference')->map(fn ($value) => (float) $value)->all());
        $this->assertFalse($customers->every(fn (array $row) => $row['matches']));
        $this->assertNotNull(
            $comparisons->first(fn ($row) => $row['scope'] === 'accounts_payable_seller' && $row['dimension_id'] === 30),
            $comparisons->toJson(),
        );
        $this->assertNotNull($comparisons->first(fn ($row) => $row['scope'] === 'carrier_receivable' && $row['dimension_id'] === 40));
    }

    public function test_asset_reconciliation_groups_normalized_currency_through_a_strict_safe_subquery(): void
    {
        DB::table('assets')->insert([
            ['name' => 'Legacy blank currency', 'price' => 100, 'depreciation_price' => 80, 'currency' => '', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'NIS asset', 'price' => 200, 'depreciation_price' => 150, 'currency' => 'شيكل', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'USD asset', 'price' => 50, 'depreciation_price' => 40, 'currency' => 'دولار', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::flushQueryLog();
        DB::enableQueryLog();

        $comparisons = collect(app(AccountingReconciliationService::class)->reconcile()['comparisons']);

        $fixedAssets = $comparisons->where('scope', 'fixed_assets')->keyBy('currency');
        $depreciation = $comparisons->where('scope', 'accumulated_depreciation')->keyBy('currency');
        $this->assertEqualsWithDelta(300, $fixedAssets->get('شيكل')['operational_balance'], 0.0001);
        $this->assertEqualsWithDelta(50, $fixedAssets->get('دولار')['operational_balance'], 0.0001);
        $this->assertEqualsWithDelta(70, $depreciation->get('شيكل')['operational_balance'], 0.0001);
        $this->assertEqualsWithDelta(10, $depreciation->get('دولار')['operational_balance'], 0.0001);

        $assetQueries = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $sql) => str_contains(strtolower($sql), 'normalized_assets'));
        $this->assertCount(2, $assetQueries);
        $this->assertTrue($assetQueries->every(
            fn (string $sql) => str_contains(strtolower($sql), 'group by "normalized_currency"')
                && ! str_contains(strtolower($sql), 'group by coalesce(nullif(currency')
        ));
    }

    public function test_debt_balance_repair_preview_is_read_only_and_repair_only_changes_derived_balance(): void
    {
        $firstId = DB::table('debt_transactions')->insertGetId([
            'customer_id' => 10, 'type' => 'taken', 'amount' => 100, 'currency' => 'شيكل', 'balance_after' => 999,
            'transaction_date' => '2026-09-01', 'source' => 'legacy_test', 'source_id' => 1, 'created_at' => now(), 'updated_at' => '2026-09-01 10:00:00',
        ]);
        $secondId = DB::table('debt_transactions')->insertGetId([
            'customer_id' => 10, 'type' => 'given', 'amount' => 40, 'currency' => 'شيكل', 'balance_after' => 888,
            'transaction_date' => '2026-09-10', 'source' => 'legacy_test', 'source_id' => 2, 'created_at' => now(), 'updated_at' => '2026-09-01 11:00:00',
        ]);
        $before = DB::table('debt_transactions')->where('id', $secondId)->first();
        $repair = app(DebtLedgerBalanceRepairService::class);

        $preview = $repair->run(true);
        $this->assertSame(2, $preview['summary']['total_issues']);
        $this->assertEqualsWithDelta(999, (float) DB::table('debt_transactions')->where('id', $firstId)->value('balance_after'), 0.0001);

        $result = $repair->run(false);
        $this->assertSame(2, $result['summary']['repaired_rows']);
        $this->assertSame(0, $result['summary']['remaining_issues']);
        $this->assertEqualsWithDelta(100, (float) DB::table('debt_transactions')->where('id', $firstId)->value('balance_after'), 0.0001);
        $this->assertEqualsWithDelta(60, (float) DB::table('debt_transactions')->where('id', $secondId)->value('balance_after'), 0.0001);
        $after = DB::table('debt_transactions')->where('id', $secondId)->first();
        $this->assertSame($before->amount, $after->amount);
        $this->assertSame($before->source, $after->source);
        $this->assertSame($before->updated_at, $after->updated_at);
    }

    public function test_backdated_customer_transaction_reprojects_later_party_journal_without_duplicates(): void
    {
        $customer = Customer::query()->create(['name' => 'Timeline Customer', 'is_canceled' => false]);
        $box = Box::query()->create(['name' => 'Timeline cash', 'total' => 500, 'currency' => 'شيكل']);
        $ledger = app(DebtLedgerService::class);
        $sale = ProfitSale::withoutEvents(fn () => ProfitSale::query()->create([
            'customer_id' => $customer->id, 'total_cost' => 150, 'payment_box_value' => 0, 'status' => 'active',
        ]));
        DB::table('profit_sales')->where('id', $sale->id)->update(['created_at' => '2026-09-10 10:00:00', 'updated_at' => '2026-09-10 10:00:00']);

        $ledger->createTransaction([
            'customer_id' => $customer->id, 'type' => 'taken', 'amount' => 100, 'currency' => 'شيكل',
            'transaction_date' => '2026-09-01', 'source' => 'legacy_seed', 'source_id' => 1,
        ], applyBox: false, logActivity: false);
        $saleDebt = $ledger->createTransaction([
            'customer_id' => $customer->id, 'type' => 'given', 'amount' => 150, 'currency' => 'شيكل',
            'transaction_date' => '2026-09-10', 'source' => 'profit_sale', 'source_id' => $sale->id,
        ], applyBox: false, logActivity: false);
        $entry = app(AccountingProjectionService::class)->syncOrFail($sale->fresh());
        $this->assertEqualsWithDelta(100, $this->partyAccountAmount($entry->id, 'accounts_payable', 'debit'), 0.0001);
        $this->assertEqualsWithDelta(50, $this->partyAccountAmount($entry->id, 'accounts_receivable', 'debit'), 0.0001);

        $ledger->createTransaction([
            'customer_id' => $customer->id, 'type' => 'given', 'amount' => 20, 'currency' => 'شيكل',
            'transaction_date' => '2026-09-05', 'box_id' => $box->id, 'source' => 'manual',
        ], logActivity: false);

        $this->assertEqualsWithDelta(-70, (float) $saleDebt->fresh()->balance_after, 0.0001);
        $this->assertEqualsWithDelta(80, $this->partyAccountAmount($entry->id, 'accounts_payable', 'debit'), 0.0001);
        $this->assertEqualsWithDelta(70, $this->partyAccountAmount($entry->id, 'accounts_receivable', 'debit'), 0.0001);
        $this->assertSame(1, AccountingJournalEntry::query()->where('source_type', 'profit_sale')->where('source_id', $sale->id)->count());
        $this->assertJournalBalanced($entry->fresh('lines'));
    }

    public function test_backdated_seller_transaction_reprojects_later_purchase_journal_without_duplicates(): void
    {
        $seller = Seller::query()->create(['name' => 'Timeline Supplier', 'is_canceled' => false]);
        $box = Box::query()->create(['name' => 'Supplier cash', 'total' => 100, 'currency' => 'شيكل']);
        $ledger = app(DebtLedgerService::class);
        DB::table('bills')->insert([
            'id' => 100, 'seller_id' => $seller->id, 'currency' => 'شيكل', 'final_total' => 150,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $receipt = PurchaseReceipt::withoutEvents(fn () => PurchaseReceipt::query()->create([
            'bill_id' => 100, 'receipt_number' => 'TL-100', 'received_at' => '2026-09-10',
        ]));
        DB::table('purchase_receipt_items')->insert([
            'purchase_receipt_id' => $receipt->id, 'product_id' => 1, 'accepted_quantity' => 1, 'unit_price' => 150,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $ledger->createTransaction([
            'seller_id' => $seller->id, 'type' => 'given', 'amount' => 100, 'currency' => 'شيكل',
            'transaction_date' => '2026-09-01', 'source' => 'legacy_seed', 'source_id' => 2,
        ], applyBox: false, logActivity: false);
        $purchaseDebt = $ledger->createTransaction([
            'seller_id' => $seller->id, 'type' => 'taken', 'amount' => 150, 'currency' => 'شيكل',
            'transaction_date' => '2026-09-10', 'source' => 'purchase_invoice', 'source_id' => 100,
        ], applyBox: false, logActivity: false);
        $entry = app(AccountingProjectionService::class)->syncOrFail($receipt->fresh());
        $this->assertEqualsWithDelta(100, $this->partyAccountAmount($entry->id, 'accounts_receivable', 'credit'), 0.0001);
        $this->assertEqualsWithDelta(50, $this->partyAccountAmount($entry->id, 'accounts_payable', 'credit'), 0.0001);

        $ledger->createTransaction([
            'seller_id' => $seller->id, 'type' => 'taken', 'amount' => 20, 'currency' => 'شيكل',
            'transaction_date' => '2026-09-05', 'box_id' => $box->id, 'source' => 'manual',
        ], logActivity: false);

        $this->assertEqualsWithDelta(70, (float) $purchaseDebt->fresh()->balance_after, 0.0001);
        $this->assertEqualsWithDelta(80, $this->partyAccountAmount($entry->id, 'accounts_receivable', 'credit'), 0.0001);
        $this->assertEqualsWithDelta(70, $this->partyAccountAmount($entry->id, 'accounts_payable', 'credit'), 0.0001);
        $this->assertSame(1, AccountingJournalEntry::query()->where('source_type', 'purchase_receipt')->where('source_id', $receipt->id)->count());
        $this->assertJournalBalanced($entry->fresh('lines'));
    }

    public function test_purchase_payments_use_payment_identity_and_legacy_inspection_repairs_only_proven_source_ids(): void
    {
        $seller = Seller::query()->create(['name' => 'Payment Supplier', 'is_canceled' => false]);
        $box = Box::query()->create(['name' => 'Purchase cash', 'total' => 20000, 'currency' => 'شيكل']);
        $bill = \App\Models\Bill::query()->create([
            'seller_id' => $seller->id, 'currency' => 'شيكل', 'total' => 10000,
            'final_total' => 10000, 'paid_amount' => 0, 'payment_status' => 'unpaid',
        ]);
        app(DebtLedgerService::class)->createTransaction([
            'seller_id' => $seller->id, 'type' => 'taken', 'amount' => 10000, 'currency' => 'شيكل',
            'transaction_date' => now()->toDateString(), 'source' => 'purchase_invoice', 'source_id' => $bill->id,
        ], applyBox: false, logActivity: false);

        $payments = collect([5000, 1000, 2000, 2000])->map(
            fn (int $amount) => app(PurchasingService::class)->recordPayment($bill->fresh(), $amount, $box->id),
        );
        $this->assertCount(4, $payments);
        foreach ($payments as $payment) {
            $transaction = DebtTransaction::query()->findOrFail($payment->debt_transaction_id);
            $this->assertSame('purchase_payment', $transaction->source);
            $this->assertSame((int) $payment->id, (int) $transaction->source_id);
            $this->assertSame(1, AccountingJournalEntry::query()
                ->where('source_type', 'purchase_payment')->where('source_id', $payment->id)->count());
        }
        $this->assertEqualsWithDelta(10000, (float) $box->fresh()->total, 0.0001);

        $initialBill = \App\Models\Bill::query()->create([
            'seller_id' => $seller->id, 'currency' => 'شيكل', 'total' => 1000,
            'final_total' => 1000, 'paid_amount' => 0, 'payment_status' => 'unpaid',
        ]);
        app(DebtLedgerService::class)->createTransaction([
            'seller_id' => $seller->id, 'type' => 'taken', 'amount' => 1000, 'currency' => 'شيكل',
            'transaction_date' => now()->toDateString(), 'source' => 'purchase_invoice', 'source_id' => $initialBill->id,
        ], applyBox: false, logActivity: false);
        $initial = app(PurchasingService::class)->recordPayment($initialBill, 300, $box->id, 'initial_payment');
        $initialTx = DebtTransaction::query()->findOrFail($initial->debt_transaction_id);
        $this->assertSame('purchase_initial_payment', $initialTx->source);
        $this->assertSame((int) $initial->id, (int) $initialTx->source_id);

        $legacy = $payments->get(1);
        DB::table('debt_transactions')->where('id', $legacy->debt_transaction_id)->update(['source_id' => $bill->id]);
        $inspection = app(PurchasePaymentSourceIdentityService::class)->run();
        $item = collect($inspection['items'])->firstWhere('purchase_payment_id', $legacy->id);
        $this->assertSame('SAFE_TO_REPAIR', $item['status']);
        $this->assertSame($bill->id, (int) DebtTransaction::query()->find($legacy->debt_transaction_id)->source_id);

        $repaired = app(PurchasePaymentSourceIdentityService::class)->run(true);
        $this->assertSame(1, $repaired['summary']['repaired']);
        $this->assertSame((int) $legacy->id, (int) DebtTransaction::query()->find($legacy->debt_transaction_id)->source_id);
    }

    public function test_open_purchase_initial_payment_moves_cash_and_posts_on_its_payment_date(): void
    {
        Carbon::setTestNow('2026-09-01 08:00:00');
        $admin = User::query()->create(['name' => 'Purchase Admin', 'type' => 'admin']);
        $seller = Seller::query()->create(['name' => 'Pay Now Supplier', 'is_canceled' => false]);
        $box = Box::query()->create(['name' => 'Pay now cash', 'total' => 10000, 'currency' => 'شيكل']);

        $bill = app(PurchasingService::class)->createPurchase([
            'seller_id' => $seller->id,
            'products' => [],
            'total' => 10000,
            'currency' => 'شيكل',
            'initial_payment' => 3000,
            'box_id' => $box->id,
        ], $admin->id);

        $payment = PurchasePayment::query()->where('bill_id', $bill->id)->sole();
        $transaction = DebtTransaction::query()->findOrFail($payment->debt_transaction_id);
        $this->assertEqualsWithDelta(7000, (float) $box->fresh()->total, 0.0001);
        $this->assertSame('purchase_initial_payment', $transaction->source);
        $this->assertSame((int) $payment->id, (int) $transaction->source_id);
        $this->assertSame('2026-09-01', $transaction->transaction_date?->format('Y-m-d'));
        $entry = AccountingJournalEntry::query()
            ->where('source_type', 'purchase_payment')
            ->where('source_id', $payment->id)
            ->firstOrFail();
        $this->assertSame('2026-09-01', $entry->entry_date?->format('Y-m-d'));
        $this->assertEqualsWithDelta(3000, $this->partyAccountAmount($entry->id, 'cash', 'credit'), 0.0001);
        $this->assertEqualsWithDelta(0, $this->partyAccountAmount($entry->id, 'inventory', 'debit'), 0.0001);
        $this->assertSame(1, AccountingJournalEntry::query()
            ->where('source_type', 'purchase_payment')
            ->where('source_id', $payment->id)
            ->count());
    }

    public function test_employee_cannot_create_initial_purchase_payment_from_hidden_box(): void
    {
        $employeeUser = User::query()->create(['name' => 'Purchase Employee', 'type' => 'employee']);
        $employee = EmployeeDetail::query()->create(['user_id' => $employeeUser->id]);
        $seller = Seller::query()->create(['name' => 'Restricted Supplier', 'is_canceled' => false]);
        $visible = Box::query()->create(['name' => 'Purchase visible', 'total' => 5000, 'currency' => 'شيكل']);
        $hidden = Box::query()->create(['name' => 'Purchase hidden', 'total' => 5000, 'currency' => 'شيكل']);
        DB::table('employee_visible_boxes')->insert([
            'employee_id' => $employee->id,
            'box_id' => $visible->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            app(PurchasingService::class)->createPurchase([
                'seller_id' => $seller->id,
                'products' => [],
                'total' => 1000,
                'initial_payment' => 300,
                'box_id' => $hidden->id,
            ], $employeeUser->id);
            $this->fail('Hidden purchase box should be rejected.');
        } catch (\App\Exceptions\BoxAccessDeniedException $exception) {
            $this->assertSame('الصندوق غير متاح لك.', $exception->getMessage());
        }

        $this->assertSame(0, \App\Models\Bill::query()->count());
        $this->assertSame(0, PurchasePayment::query()->count());
        $this->assertSame(0, DebtTransaction::query()->count());
        $this->assertEqualsWithDelta(5000, (float) $hidden->fresh()->total, 0.0001);
    }

    public function test_finalize_does_not_deduct_initial_payment_twice_and_keeps_later_payment_date(): void
    {
        Carbon::setTestNow('2026-09-01 08:00:00');
        $admin = User::query()->create(['name' => 'Finalize Admin', 'type' => 'admin']);
        $seller = Seller::query()->create(['name' => 'Finalize Supplier', 'is_canceled' => false]);
        $box = Box::query()->create(['name' => 'Finalize cash', 'total' => 20000, 'currency' => 'شيكل']);
        $service = app(PurchasingService::class);

        $bill = $service->createPurchase([
            'seller_id' => $seller->id,
            'products' => [],
            'total' => 10000,
            'initial_payment' => 3000,
            'box_id' => $box->id,
        ], $admin->id);
        $this->insertReceivedBillItem($bill->id, 10000);
        $service->finalize($bill->fresh(), 0, null, $admin->id);

        $this->assertEqualsWithDelta(17000, (float) $box->fresh()->total, 0.0001);
        $this->assertSame(1, PurchasePayment::query()->where('bill_id', $bill->id)->count());
        $initial = PurchasePayment::query()->where('bill_id', $bill->id)->firstOrFail();
        $this->assertSame(1, DebtTransaction::query()
            ->where('source', 'purchase_initial_payment')
            ->where('source_id', $initial->id)
            ->count());

        $secondBill = $service->createPurchase([
            'seller_id' => $seller->id,
            'products' => [],
            'total' => 10000,
            'initial_payment' => 3000,
            'box_id' => $box->id,
        ], $admin->id);
        $this->insertReceivedBillItem($secondBill->id, 10000);
        Carbon::setTestNow('2026-09-03 12:00:00');
        $service->finalize($secondBill->fresh(), 2000, $box->id, $admin->id);

        $payments = PurchasePayment::query()->where('bill_id', $secondBill->id)->orderBy('id')->get();
        $this->assertCount(2, $payments);
        $this->assertSame(['initial_payment', 'payment'], $payments->pluck('type')->all());
        $this->assertSame(
            ['2026-09-01', '2026-09-03'],
            $payments->map(fn (PurchasePayment $payment) => $payment->paid_at?->format('Y-m-d'))->all(),
        );
        $this->assertEqualsWithDelta(12000, (float) $box->fresh()->total, 0.0001);
        foreach ($payments as $payment) {
            $transaction = DebtTransaction::query()->findOrFail($payment->debt_transaction_id);
            $this->assertSame((int) $payment->id, (int) $transaction->source_id);
            $this->assertSame($payment->paid_at?->format('Y-m-d'), $transaction->transaction_date?->format('Y-m-d'));
            $entry = AccountingJournalEntry::query()
                ->where('source_type', 'purchase_payment')
                ->where('source_id', $payment->id)
                ->firstOrFail();
            $this->assertSame($payment->paid_at?->format('Y-m-d'), $entry->entry_date?->format('Y-m-d'));
        }
    }

    public function test_integrity_reports_legacy_initial_payments_without_cash_link_without_repairing_them(): void
    {
        $seller = Seller::query()->create(['name' => 'Legacy Initial Supplier', 'is_canceled' => false]);
        $box = Box::query()->create(['name' => 'Legacy cash', 'total' => 5000, 'currency' => 'شيكل']);
        $bill = \App\Models\Bill::query()->create([
            'seller_id' => $seller->id,
            'currency' => 'شيكل',
            'total' => 1000,
            'workflow_status' => 'awaiting_receiving',
        ]);
        $payment = PurchasePayment::withoutEvents(fn () => PurchasePayment::query()->create([
            'bill_id' => $bill->id,
            'seller_id' => $seller->id,
            'box_id' => $box->id,
            'amount' => 300,
            'currency' => 'شيكل',
            'type' => 'initial_payment',
            'paid_at' => '2026-08-31',
            'debt_transaction_id' => null,
        ]));

        $check = collect(app(AccountingIntegrityService::class)->run()['checks'])
            ->firstWhere('name', 'purchase_initial_payment_pending_cash');
        $this->assertSame('WARNING', $check['status']);
        $this->assertSame($payment->id, $check['details']['items'][0]['payment_id']);
        $this->assertSame($bill->id, $check['details']['items'][0]['bill_id']);
        $this->assertSame($box->id, $check['details']['items'][0]['box_id']);
        $this->assertEqualsWithDelta(300, $check['details']['items'][0]['amount'], 0.0001);
        $this->assertSame('2026-08-31', $check['details']['items'][0]['paid_at']);
        $this->assertSame('awaiting_receiving', $check['details']['items'][0]['bill_workflow_status']);
        $this->assertNull($payment->fresh()->debt_transaction_id);
        $this->assertEqualsWithDelta(5000, (float) $box->fresh()->total, 0.0001);
    }

    public function test_finalize_syncs_one_legacy_initial_payment_once_using_payment_identity(): void
    {
        Carbon::setTestNow('2026-09-03 10:00:00');
        $admin = User::query()->create(['name' => 'Legacy Finalize Admin', 'type' => 'admin']);
        $seller = Seller::query()->create(['name' => 'Legacy Finalize Supplier', 'is_canceled' => false]);
        $box = Box::query()->create(['name' => 'Legacy finalize cash', 'total' => 10000, 'currency' => 'شيكل']);
        $bill = \App\Models\Bill::query()->create([
            'seller_id' => $seller->id,
            'currency' => 'شيكل',
            'total' => 10000,
            'paid_amount' => 3000,
            'workflow_status' => 'received',
            'payment_status' => 'partially_paid',
        ]);
        $this->insertReceivedBillItem($bill->id, 10000);
        $payment = PurchasePayment::withoutEvents(fn () => PurchasePayment::query()->create([
            'bill_id' => $bill->id,
            'seller_id' => $seller->id,
            'box_id' => $box->id,
            'amount' => 3000,
            'currency' => 'شيكل',
            'type' => 'initial_payment',
            'paid_at' => '2026-09-01',
            'debt_transaction_id' => null,
            'created_by' => $admin->id,
        ]));

        $service = app(PurchasingService::class);
        $service->finalize($bill->fresh(), 0, null, $admin->id);
        $linkedTransactionId = $payment->fresh()->debt_transaction_id;
        $this->assertNotNull($linkedTransactionId);
        $this->assertEqualsWithDelta(7000, (float) $box->fresh()->total, 0.0001);
        $this->assertDatabaseHas('debt_transactions', [
            'id' => $linkedTransactionId,
            'source' => 'purchase_initial_payment',
            'source_id' => $payment->id,
        ]);
        $this->assertSame(
            '2026-09-01',
            DebtTransaction::query()->findOrFail($linkedTransactionId)->transaction_date?->format('Y-m-d'),
        );

        $service->finalize($bill->fresh(), 0, null, $admin->id);
        $this->assertEqualsWithDelta(7000, (float) $box->fresh()->total, 0.0001);
        $this->assertSame(1, DebtTransaction::query()
            ->where('source', 'purchase_initial_payment')
            ->where('source_id', $payment->id)
            ->count());
    }

    public function test_payment_receive_preflight_rejects_hidden_box_before_earlier_cash_mutation(): void
    {
        $employeeUser = User::query()->create(['name' => 'Atomic Employee', 'type' => 'employee']);
        $employee = EmployeeDetail::query()->create(['user_id' => $employeeUser->id]);
        $customer = Customer::query()->create(['name' => 'Atomic Customer', 'is_canceled' => false]);
        $visible = Box::query()->create(['name' => 'Visible cash', 'total' => 1000, 'currency' => 'شيكل']);
        $hidden = Box::query()->create(['name' => 'Hidden cash', 'total' => 1000, 'currency' => 'شيكل']);
        DB::table('employee_visible_boxes')->insert([
            'employee_id' => $employee->id,
            'box_id' => $visible->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($employeeUser);

        $this->withoutMiddleware()->postJson('/api/add/transaction', [
            'type' => 'receive',
            'customer_id' => $customer->id,
            'box_id' => $visible->id,
            'box_value' => 300,
            'box_log_note' => 'must rollback',
            'debts' => [['total' => 50, 'box_id' => $hidden->id]],
        ])->assertOk()->assertJsonPath('message', 'الصندوق غير متاح لك.');

        $this->assertEqualsWithDelta(1000, (float) $visible->fresh()->total, 0.0001);
        $this->assertEqualsWithDelta(1000, (float) $hidden->fresh()->total, 0.0001);
        $this->assertSame(0, BoxLog::query()->count());
        $this->assertSame(0, DebtTransaction::query()->count());
        $this->assertSame(0, AccountingJournalEntry::query()->count());
    }

    public function test_payment_receive_preflight_aggregates_all_cash_out_for_each_box(): void
    {
        $admin = User::query()->create(['name' => 'Atomic Admin', 'type' => 'admin']);
        $customer = Customer::query()->create(['name' => 'Combined Customer', 'is_canceled' => false]);
        $box = Box::query()->create(['name' => 'Combined cash', 'total' => 1000, 'currency' => 'شيكل']);
        $this->actingAs($admin);

        $this->withoutMiddleware()->postJson('/api/add/transaction', [
            'type' => 'payment',
            'customer_id' => $customer->id,
            'box_id' => $box->id,
            'box_value' => 600,
            'debts' => [['total' => 500, 'box_id' => $box->id]],
        ])->assertOk()->assertJsonPath('status', 'error');

        $this->assertEqualsWithDelta(1000, (float) $box->fresh()->total, 0.0001);
        $this->assertSame(0, BoxLog::query()->count());
        $this->assertSame(0, DebtTransaction::query()->count());
        $this->assertSame(0, AccountingJournalEntry::query()->count());
    }

    public function test_payment_receive_commits_cash_check_and_debt_together_without_duplicate_journals(): void
    {
        $admin = User::query()->create(['name' => 'Atomic Success Admin', 'type' => 'admin']);
        $customer = Customer::query()->create(['name' => 'Atomic Success Customer', 'is_canceled' => false]);
        $box = Box::query()->create(['name' => 'Atomic success cash', 'total' => 2000, 'currency' => 'شيكل']);
        $this->actingAs($admin);

        $this->withoutMiddleware()->postJson('/api/add/transaction', [
            'type' => 'payment',
            'customer_id' => $customer->id,
            'box_id' => $box->id,
            'box_value' => 300,
            'checks' => [[
                'check_value' => 200,
                'check_currency' => 'شيكل',
                'check_id' => 'UNIT-ATOMIC-1',
                'bank_name' => 'Unit Bank',
            ]],
            'debts' => [['total' => 400, 'box_id' => $box->id, 'due_date' => '2026-09-06']],
        ])->assertOk()->assertJsonPath('status', 'success');

        $check = OutgoingCheck::query()->where('check_id', 'UNIT-ATOMIC-1')->firstOrFail();
        $manual = DebtTransaction::query()->where('source', 'manual')->firstOrFail();
        $projection = app(AccountingProjectionService::class);
        $projection->syncOrFail($check->fresh());
        $projection->syncOrFail($check->fresh());
        $projection->syncOrFail($manual->fresh());
        $projection->syncOrFail($manual->fresh());

        $this->assertEqualsWithDelta(1300, (float) $box->fresh()->total, 0.0001);
        $this->assertSame(2, BoxLog::query()->count());
        $this->assertSame(2, DebtTransaction::query()->where('customer_id', $customer->id)->count());
        $this->assertSame(1, AccountingJournalEntry::query()
            ->where('source_type', 'outgoing_check')->where('source_id', $check->id)->count());
        $this->assertSame(1, AccountingJournalEntry::query()
            ->where('source_type', 'debt_transaction')->where('source_id', $manual->id)->count());
    }

    public function test_payment_receive_rolls_back_early_cash_when_later_component_throws(): void
    {
        $admin = User::query()->create(['name' => 'Atomic Failure Admin', 'type' => 'admin']);
        $customer = Customer::query()->create(['name' => 'Atomic Failure Customer', 'is_canceled' => false]);
        $box = Box::query()->create(['name' => 'Atomic failure cash', 'total' => 1000, 'currency' => 'شيكل']);
        $this->actingAs($admin);
        $this->mock(DebtLedgerService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('createTransaction')
                ->once()
                ->andThrow(new RuntimeException('forced later component failure'));
        });

        $this->withoutMiddleware()->postJson('/api/add/transaction', [
            'type' => 'payment',
            'customer_id' => $customer->id,
            'box_id' => $box->id,
            'box_value' => 300,
            'debts' => [['total' => 200, 'box_id' => $box->id]],
        ])->assertOk()->assertJsonPath('status', 'error');

        $this->assertEqualsWithDelta(1000, (float) $box->fresh()->total, 0.0001);
        $this->assertSame(0, BoxLog::query()->count());
        $this->assertSame(0, DebtTransaction::query()->count());
        $this->assertSame(0, AccountingJournalEntry::query()->count());
    }

    public function test_payment_receive_removes_new_check_image_when_database_transaction_rolls_back(): void
    {
        $admin = User::query()->create(['name' => 'Atomic File Admin', 'type' => 'admin']);
        $customer = Customer::query()->create(['name' => 'Atomic File Customer', 'is_canceled' => false]);
        $box = Box::query()->create(['name' => 'Atomic file cash', 'total' => 1000, 'currency' => 'شيكل']);
        $this->actingAs($admin);
        $this->mock(DebtLedgerService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('syncOutgoingCheckToLedger')
                ->once()
                ->andThrow(new RuntimeException('forced check sync failure'));
        });
        $directory = public_path('OutgoingChecksImages');
        $before = File::isDirectory($directory) ? File::files($directory) : [];
        $beforePaths = collect($before)->map(fn ($file) => $file->getPathname())->sort()->values()->all();

        $this->withoutMiddleware()->post('/api/add/transaction', [
            'type' => 'payment',
            'customer_id' => $customer->id,
            'box_id' => $box->id,
            'box_value' => 100,
            'checks' => [[
                'check_value' => 200,
                'check_currency' => 'شيكل',
                'check_id' => 'UNIT-FILE-ROLLBACK',
                'bank_name' => 'Unit Bank',
                'img' => UploadedFile::fake()->image('rollback.png'),
            ]],
        ])->assertOk()->assertJsonPath('status', 'error');

        $after = File::isDirectory($directory) ? File::files($directory) : [];
        $afterPaths = collect($after)->map(fn ($file) => $file->getPathname())->sort()->values()->all();
        $this->assertSame($beforePaths, $afterPaths);
        $this->assertEqualsWithDelta(1000, (float) $box->fresh()->total, 0.0001);
        $this->assertSame(0, BoxLog::query()->count());
        $this->assertSame(0, OutgoingCheck::query()->count());
        $this->assertSame(0, DebtTransaction::query()->count());
    }

    public function test_clearing_warning_depends_on_open_balance_not_correction_history(): void
    {
        $box = Box::query()->create(['name' => 'Correction cash', 'total' => 100, 'currency' => 'شيكل']);
        $log = BoxLog::withoutEvents(fn () => BoxLog::query()->create([
            'box_id' => $box->id, 'type' => 'add', 'value' => 100,
            'description' => 'تصحيح محاسبي', 'reason_code' => 'accounting_correction',
        ]));
        app(AccountingProjectionService::class)->syncOrFail($log);

        $check = collect(app(AccountingIntegrityService::class)->run()['checks'])->firstWhere('name', 'clearing_balance');
        $this->assertSame('WARNING', $check['status']);

        app(AccountingService::class)->post('clearing:classification:'.$log->id, 'clearing_classification', $log->id, now(), 'شيكل', 'تصنيف تصحيح صندوق', [
            ['account_key' => 'clearing', 'debit' => 100, 'credit' => 0],
            ['account_key' => 'owner_equity', 'debit' => 0, 'credit' => 100],
        ]);

        $checks = collect(app(AccountingIntegrityService::class)->run()['checks']);
        $this->assertSame('PASS', $checks->firstWhere('name', 'clearing_balance')['status']);
        $this->assertSame('PASS', $checks->firstWhere('name', 'box_unclassified_adjustments')['status']);
        $this->assertDatabaseHas('box_logs', ['id' => $log->id, 'reason_code' => 'accounting_correction']);
    }

    public function test_employee_box_access_is_enforced_inside_manual_debt_service(): void
    {
        $employeeUser = User::query()->create(['name' => 'Limited Employee', 'type' => 'employee']);
        $employee = EmployeeDetail::query()->create(['user_id' => $employeeUser->id]);
        $admin = User::query()->create(['name' => 'Admin', 'type' => 'admin']);
        $customer = Customer::query()->create(['name' => 'Permission Customer', 'is_canceled' => false]);
        $boxA = Box::query()->create(['name' => 'Visible', 'total' => 500, 'currency' => 'شيكل']);
        $boxB = Box::query()->create(['name' => 'Hidden', 'total' => 500, 'currency' => 'شيكل']);
        DB::table('employee_visible_boxes')->insert([
            'employee_id' => $employee->id, 'box_id' => $boxA->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $ledger = app(DebtLedgerService::class);

        try {
            $ledger->createTransaction([
                'customer_id' => $customer->id, 'type' => 'given', 'amount' => 50,
                'transaction_date' => '2026-09-01', 'box_id' => $boxB->id, 'source' => 'manual',
            ], $employeeUser->id, actor: $employeeUser);
            $this->fail('Hidden box must be rejected.');
        } catch (\App\Exceptions\BoxAccessDeniedException $exception) {
            $this->assertSame('الصندوق غير متاح لك.', $exception->getMessage());
        }
        $this->assertSame(0, DebtTransaction::query()->count());
        $this->assertEqualsWithDelta(500, (float) $boxB->fresh()->total, 0.0001);

        $transaction = $ledger->createTransaction([
            'customer_id' => $customer->id, 'type' => 'given', 'amount' => 50,
            'transaction_date' => '2026-09-01', 'box_id' => $boxA->id, 'source' => 'manual',
        ], $employeeUser->id, actor: $employeeUser);
        try {
            $ledger->updateTransaction($transaction, [
                'type' => 'given', 'amount' => 50, 'transaction_date' => '2026-09-01', 'box_id' => $boxB->id,
            ], actor: $employeeUser);
            $this->fail('Moving a manual debt to a hidden box must be rejected.');
        } catch (\App\Exceptions\BoxAccessDeniedException) {
            // Expected.
        }
        $this->assertSame($boxA->id, $transaction->fresh()->box_id);
        $this->assertEqualsWithDelta(450, (float) $boxA->fresh()->total, 0.0001);
        $this->assertEqualsWithDelta(500, (float) $boxB->fresh()->total, 0.0001);

        $this->assertTrue(app(BoxAccessService::class)->canAccess($admin, $boxA->id));
        $this->assertTrue(app(BoxAccessService::class)->canAccess($admin, $boxB->id));
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

    private function insertReceivedBillItem(int $billId, float $price): void
    {
        DB::table('bill_items')->insert([
            'bill_id' => $billId,
            'quantity' => 1,
            'ordered_quantity' => 1,
            'received_owned_quantity' => 1,
            'custody_quantity' => 0,
            'damaged_quantity' => 0,
            'mismatched_quantity' => 0,
            'price' => $price,
            'final_unit_price' => $price,
            'status' => 'finished',
            'created_at' => now(),
            'updated_at' => now(),
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

    private function partyAccountAmount(int $entryId, string $accountKey, string $side): float
    {
        return (float) DB::table('accounting_journal_lines as lines')
            ->join('accounting_accounts as accounts', 'accounts.id', '=', 'lines.account_id')
            ->where('lines.journal_entry_id', $entryId)
            ->where('accounts.system_key', $accountKey)
            ->sum('lines.'.$side);
    }
}
