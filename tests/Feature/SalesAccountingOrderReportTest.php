<?php

namespace Tests\Feature;

use App\Services\SalesAccountingReportService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SalesAccountingOrderReportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.sales_report_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]]);
        DB::setDefaultConnection('sales_report_test');

        foreach (['customers', 'sellers', 'users'] as $tableName) {
            Schema::create($tableName, function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->softDeletes();
            });
        }
        Schema::create('instant_sales', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sales_order_id')->nullable();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->unsignedBigInteger('maintenance_id')->nullable();
            $table->string('status')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->decimal('total_cost');
            $table->timestamps();
        });
        Schema::create('sales_orders', function (Blueprint $table) {
            $table->id();
            $table->string('customer_name')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('partner_type')->nullable();
            $table->unsignedBigInteger('partner_id')->nullable();
            $table->unsignedBigInteger('instant_sale_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('status')->default('delivered');
            $table->boolean('is_debt_collection')->default(false);
            $table->decimal('total');
            $table->decimal('discount')->default(0);
            $table->decimal('payment_amount')->default(0);
            $table->timestamp('financial_posted_at')->nullable();
            $table->timestamps();
        });
        Schema::create('sales_returns', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sales_order_id');
            $table->string('status')->default('completed');
            $table->string('return_type');
            $table->decimal('total_amount');
            $table->timestamp('completed_at');
            $table->timestamp('cancelled_at')->nullable();
        });
        Schema::create('sales_return_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sales_return_id');
        });
    }

    public function test_recognized_orders_use_posting_date_and_historical_orders_are_counted_once(): void
    {
        $this->order(['total' => 90, 'discount' => 10, 'status' => 'archived']);
        $this->order(['total' => 400, 'financial_posted_at' => null, 'status' => 'returned']);
        $this->order(['total' => 400, 'status' => 'canceled']);
        $this->order(['total' => 400, 'is_debt_collection' => true]);

        $invoiceId = DB::table('instant_sales')->insertGetId(['total_cost' => 40, 'created_at' => '2026-11-02 09:00:00']);
        $this->order(['total' => 40, 'instant_sale_id' => $invoiceId]);
        $orderId = $this->order(['total' => 20]);
        DB::table('instant_sales')->insert(['total_cost' => 20, 'sales_order_id' => $orderId, 'created_at' => '2026-11-02 09:00:00']);

        $report = $this->report(['group_by' => 'day']);

        $this->assertSame(3.0, $report['summary']['invoice_count']);
        $this->assertSame(150.0, $report['summary']['sales_total']);
        $this->assertSame(160.0, $report['summary']['gross_sales']);
        $this->assertSame(['2026-11-02', '2026-11-03'], array_column($report['rows'], 'key'));
    }

    public function test_order_returns_use_the_same_party_and_employee_with_their_own_dates(): void
    {
        DB::table('sellers')->insert(['id' => 1, 'name' => 'مورد']);
        DB::table('customers')->insert(['id' => 1, 'name' => 'زبون']);
        DB::table('users')->insert(['id' => 2, 'name' => 'المندوب']);
        $sellerOrder = $this->order([
            'total' => 100, 'payment_amount' => 100, 'partner_type' => 'seller', 'partner_id' => 1, 'created_by' => 2,
        ]);
        $customerOrder = $this->order(['total' => 80, 'customer_id' => 1, 'created_by' => 2]);
        DB::table('sales_returns')->insert([
            ['sales_order_id' => $sellerOrder, 'total_amount' => 30, 'return_type' => 'partial', 'completed_at' => '2026-11-04 09:00:00'],
            ['sales_order_id' => $customerOrder, 'total_amount' => 80, 'return_type' => 'direct', 'completed_at' => '2026-11-04 10:00:00'],
        ]);

        $filters = ['representative_id' => 2, 'include_returns' => true];
        $sellerFilters = $filters + ['person_key' => 'seller:1'];
        $report = $this->report($sellerFilters);
        $this->assertSame('مورد', $report['rows'][0]['label']);
        $this->assertSame(100.0, $report['summary']['sales_total']);
        $this->assertSame(30.0, $report['summary']['returns_total']);
        $this->assertSame(70.0, $report['summary']['net_sales']);

        $customerReport = $this->report($filters + ['person_key' => 'customer:1']);
        $this->assertSame(80.0, $customerReport['summary']['returns_total']);
        $this->assertSame(0.0, $customerReport['summary']['net_sales']);

        $returnDay = $this->report($filters + ['from_date' => '2026-11-04']);
        $this->assertSame(-110.0, $returnDay['summary']['net_sales']);
        $this->assertSame(100.0, $this->report($sellerFilters + ['cash_only' => true])['summary']['net_sales']);
        $this->assertSame(0.0, $this->report($filters + ['currency' => 'دولار'])['summary']['sales_total']);
    }

    private function order(array $attributes): int
    {
        return DB::table('sales_orders')->insertGetId($attributes + [
            'created_at' => '2026-10-31 09:00:00',
            'financial_posted_at' => '2026-11-03 09:00:00',
        ]);
    }

    private function report(array $filters): array
    {
        return app(SalesAccountingReportService::class)->report($filters + [
            'from_date' => '2026-11-01', 'to_date' => '2026-11-09',
        ]);
    }
}
