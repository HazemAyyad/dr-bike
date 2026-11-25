<?php

namespace Tests\Feature;

use App\Http\Controllers\API\FinancialAccountingReportController;
use App\Services\FinancialAccountingReportService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ExpenseSummaryReportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.expense_summary_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]]);
        DB::setDefaultConnection('expense_summary_test');
        Schema::create('boxes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('currency');
        });
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('price');
            $table->string('expense_type')->default('general');
            $table->string('payment_method')->nullable();
            $table->date('expense_date');
            $table->unsignedBigInteger('box_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    private function filters(array $extra = []): array
    {
        return array_merge(['from_date' => '2026-11-01', 'to_date' => '2026-11-30', 'currency' => 'شيكل'], $extra);
    }

    private function seedExpenses(): void
    {
        DB::table('boxes')->insert([
            ['id' => 1, 'name' => 'الرئيسي', 'currency' => 'شيكل'],
            ['id' => 2, 'name' => 'الدولار', 'currency' => 'دولار'],
        ]);
        foreach ([
            [1, 'إيجار', 1500, '2026-11-03', 1],
            [2, 'ضيافة', 350, '2026-11-04', 1],
            [3, 'إيجار', 100, '2026-10-03', 1],
            [4, 'إيجار', 999, '2026-11-03', 2],
        ] as [$id, $name, $amount, $date, $box]) {
            DB::table('expenses')->insert(['id' => $id, 'name' => $name, 'price' => $amount, 'expense_date' => $date, 'box_id' => $box]);
        }
    }

    public function test_legacy_summary_is_available_with_no_invented_accounts_or_payment_data(): void
    {
        $this->seedExpenses();
        $service = app(FinancialAccountingReportService::class);
        $request = Request::create('/api/admin/accounting-reports/expenses-summary', 'GET', $this->filters(['box_id' => 1]));
        $route = Route::getRoutes()->match($request);
        $this->assertSame('expenses-summary', $route->parameter('type'));
        $response = app(FinancialAccountingReportController::class)->report($request, 'expenses-summary', $service)->getData(true)['data'];
        $this->assertEquals(1850, $response['summary']['period_total']);
        $this->assertSame(2, $response['summary']['movement_count']);
        $this->assertFalse($response['quality']['ledger_available']);
        $this->assertSame([], $response['rows'][0]['account_codes']);
        $this->assertCount(3, $response['unavailable_fields']);
        $this->assertSame('تقرير المصروفات التجميعي', $response['title']);
    }

    public function test_ledger_summary_counts_posted_sources_once_and_applies_dated_reversals(): void
    {
        $this->seedExpenses();
        Schema::create('accounting_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code');
            $table->string('name_ar');
            $table->string('type');
            $table->string('system_key')->nullable();
            $table->unsignedBigInteger('parent_id')->nullable();
        });
        Schema::create('accounting_journal_entries', function (Blueprint $table) {
            $table->id();
            $table->string('entry_number');
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');
            $table->string('status');
            $table->date('entry_date');
            $table->string('currency');
            $table->text('description')->nullable();
        });
        Schema::create('accounting_journal_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('journal_entry_id');
            $table->unsignedBigInteger('account_id');
            $table->decimal('debit');
            $table->decimal('credit');
            $table->unsignedBigInteger('box_id')->nullable();
            $table->text('description')->nullable();
        });
        DB::table('accounting_accounts')->insert([
            ['id' => 1, 'code' => '6000', 'name_ar' => 'مصاريف عمومية', 'type' => 'expense', 'system_key' => 'general_expense', 'parent_id' => null],
            ['id' => 2, 'code' => '5000', 'name_ar' => 'تكلفة المبيعات', 'type' => 'expense', 'system_key' => 'cost_of_goods_sold', 'parent_id' => null],
            ['id' => 3, 'code' => '5001', 'name_ar' => 'تكلفة فرعية', 'type' => 'expense', 'system_key' => null, 'parent_id' => 2],
        ]);
        foreach ([[1, 1, '2026-11-03', 1500, 0, 1], [2, 3, '2026-10-03', 100, 0, 1],
            [3, 3, '2026-11-05', 0, 100, 1], [4, 99, '2026-11-05', 900, 0, 3]] as [$id, $source, $date, $debit, $credit, $account]) {
            DB::table('accounting_journal_entries')->insert(['id' => $id, 'entry_number' => 'J-'.$id, 'source_type' => 'expense', 'source_id' => $source, 'status' => $id === 2 ? 'reversed' : 'posted', 'entry_date' => $date, 'currency' => 'شيكل']);
            DB::table('accounting_journal_lines')->insert(['journal_entry_id' => $id, 'account_id' => $account, 'debit' => $debit, 'credit' => $credit, 'box_id' => 1]);
        }
        $service = app(FinancialAccountingReportService::class);
        $report = $service->report('expenses-summary', $this->filters(['include_opening' => true]));
        $statement = $service->report('expenses', $this->filters());
        $this->assertSame(1750.0, $report['summary']['period_total']);
        $this->assertSame($statement['summary']['period_total'], $report['summary']['period_total']);
        $this->assertSame(3, $report['summary']['movement_count']);
        $this->assertFalse($report['filters']['include_opening']);
        $this->assertSame(['6000'], $report['rows'][0]['account_codes']);
        $this->assertSame(80.0, $report['rows'][0]['percent']);
        $filtered = $service->report('expenses-summary', $this->filters(['category_key' => $report['rows'][0]['key'], 'box_id' => 1]));
        $this->assertSame(1400.0, $filtered['summary']['period_total']);
        $this->assertSame(2, $filtered['summary']['movement_count']);
    }
}
