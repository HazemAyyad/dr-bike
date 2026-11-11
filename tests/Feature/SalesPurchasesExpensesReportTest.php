<?php

namespace Tests\Feature;

use App\Services\FinancialAccountingReportService;
use App\Services\SalesAccountingReportService;
use App\Services\SalesPurchasesExpensesReportService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SalesPurchasesExpensesReportTest extends TestCase
{
    public function test_receipts_are_counted_on_receipt_dates_and_period_totals_match_daily_rows(): void
    {
        config(['database.connections.trading_report_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]]);
        DB::setDefaultConnection('trading_report_test');

        Schema::create('bills', function (Blueprint $table) {
            $table->id();
            $table->string('status');
            $table->string('workflow_status')->nullable();
            $table->string('currency')->nullable();
        });
        Schema::create('purchase_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bill_id');
            $table->date('received_at')->nullable();
            $table->timestamps();
        });
        Schema::create('purchase_receipt_items', function (Blueprint $table) {
            $table->foreignId('purchase_receipt_id');
            $table->decimal('accepted_quantity', 14, 4);
            $table->decimal('unit_price', 14, 4);
        });

        DB::table('bills')->insert([
            ['id' => 1, 'status' => 'unfinished', 'workflow_status' => 'partially_received', 'currency' => 'NIS'],
            ['id' => 2, 'status' => 'finished', 'workflow_status' => 'finalized', 'currency' => 'دولار'],
            ['id' => 3, 'status' => 'cancelled', 'workflow_status' => 'cancelled', 'currency' => 'شيكل'],
            ['id' => 4, 'status' => 'finished', 'workflow_status' => null, 'currency' => null],
        ]);
        DB::table('purchase_receipts')->insert([
            ['id' => 1, 'bill_id' => 1, 'received_at' => '2026-09-01', 'created_at' => '2026-09-05 13:00:00'],
            ['id' => 2, 'bill_id' => 1, 'received_at' => '2026-09-03', 'created_at' => '2026-09-05 13:00:00'],
            ['id' => 3, 'bill_id' => 2, 'received_at' => '2026-09-01', 'created_at' => '2026-09-01 13:00:00'],
            ['id' => 4, 'bill_id' => 3, 'received_at' => '2026-09-01', 'created_at' => '2026-09-01 13:00:00'],
            ['id' => 5, 'bill_id' => 4, 'received_at' => null, 'created_at' => '2026-09-03 13:00:00'],
            ['id' => 6, 'bill_id' => 1, 'received_at' => '2026-09-04', 'created_at' => '2026-09-03 13:00:00'],
        ]);
        DB::table('purchase_receipt_items')->insert([
            ['purchase_receipt_id' => 1, 'accepted_quantity' => 2, 'unit_price' => 40],
            ['purchase_receipt_id' => 1, 'accepted_quantity' => 0, 'unit_price' => 900],
            ['purchase_receipt_id' => 2, 'accepted_quantity' => 3, 'unit_price' => 20],
            ['purchase_receipt_id' => 3, 'accepted_quantity' => 5, 'unit_price' => 60],
            ['purchase_receipt_id' => 4, 'accepted_quantity' => 2, 'unit_price' => 100],
            ['purchase_receipt_id' => 5, 'accepted_quantity' => 0.5, 'unit_price' => 25],
            ['purchase_receipt_id' => 6, 'accepted_quantity' => 1, 'unit_price' => 900],
        ]);

        $filters = ['from_date' => '2026-09-01', 'to_date' => '2026-09-03', 'currency' => 'شيكل'];
        $this->mock(SalesAccountingReportService::class)->shouldReceive('report')->once()
            ->with($filters + ['group_by' => 'day', 'include_returns' => true])->andReturn(['rows' => [
                ['key' => '2026-09-01', 'net_sales' => 220.0],
                ['key' => '2026-09-03', 'net_sales' => -20.0],
            ]]);
        $this->mock(FinancialAccountingReportService::class)->shouldReceive('report')->once()
            ->with('expenses', $filters)->andReturn(['quality' => ['ledger_available' => true], 'rows' => [
                ['date' => '2026-09-01', 'debit' => 35, 'credit' => 0],
                ['date' => '2026-09-01', 'debit' => 0, 'credit' => 5],
                ['date' => '2026-09-03', 'debit' => 0, 'credit' => 4],
            ]]);

        $report = app(SalesPurchasesExpensesReportService::class)->report($filters);

        $this->assertSame([
            ['date' => '2026-09-01', 'sales' => 220.0, 'purchases' => 80.0, 'expenses' => 30.0],
            ['date' => '2026-09-02', 'sales' => 0.0, 'purchases' => 0.0, 'expenses' => 0.0],
            ['date' => '2026-09-03', 'sales' => -20.0, 'purchases' => 72.5, 'expenses' => -4.0],
        ], $report['rows']);
        $this->assertSame(['sales' => 200.0, 'purchases' => 152.5, 'expenses' => 26.0], $report['summary']);
    }
}
