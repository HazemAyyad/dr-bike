<?php

namespace Tests\Feature;

use App\Services\FinancialAccountingReportBuilder;
use App\Services\IncomeStatementDetailsService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class IncomeStatementDetailsTest extends TestCase
{
    public function test_income_details_reconcile_inventory_movements_and_keep_the_recorded_profit(): void
    {
        config(['database.connections.income_details_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]]);
        DB::setDefaultConnection('income_details_test');

        Schema::create('accounting_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('system_key')->nullable();
        });
        Schema::create('accounting_cutovers', function (Blueprint $table) {
            $table->id();
            $table->date('cutover_date');
            $table->string('status');
        });
        Schema::create('accounting_journal_entries', function (Blueprint $table) {
            $table->id();
            $table->date('entry_date');
            $table->string('source_type');
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedBigInteger('reverses_entry_id')->nullable();
            $table->string('status');
            $table->string('currency');
        });
        Schema::create('accounting_journal_lines', function (Blueprint $table) {
            $table->unsignedBigInteger('journal_entry_id');
            $table->unsignedBigInteger('account_id');
            $table->decimal('debit', 14, 4);
            $table->decimal('credit', 14, 4);
        });
        Schema::create('instant_sales', function (Blueprint $table) {
            $table->id();
            $table->decimal('discount');
            $table->decimal('total_cost');
        });

        DB::table('accounting_accounts')->insert([
            ['id' => 1, 'parent_id' => null, 'system_key' => 'inventory'],
            ['id' => 2, 'parent_id' => 1, 'system_key' => null],
            ['id' => 3, 'parent_id' => null, 'system_key' => 'cost_of_goods_sold'],
        ]);
        DB::table('accounting_cutovers')->insert(['cutover_date' => '2026-09-01', 'status' => 'applied']);
        DB::table('instant_sales')->insert(['id' => 7, 'discount' => 100, 'total_cost' => 900]);

        $this->insertEntry('2026-09-01', 'accounting_cutover', [[1, 1000, 0]]);
        $this->insertEntry('2026-09-01', 'purchase_receipt', [[2, 300, 0]]);
        $receipt = $this->insertEntry('2026-09-02', 'purchase_receipt', [[1, 100, 0]], ['status' => 'reversed']);
        $this->insertEntry('2026-09-03', 'purchase_receipt', [[1, 0, 100]], ['reverses_entry_id' => $receipt]);
        $this->insertEntry('2026-09-04', 'purchase_return', [[2, 0, 40]]);
        $this->insertEntry('2026-09-05', 'inventory_adjustment', [[1, 0, 20]]);
        $this->insertEntry('2026-09-06', 'instant_sale', [[1, 0, 500], [3, 500, 0]], ['source_id' => 7]);
        $this->insertEntry('2026-09-07', 'sales_return', [[1, 25, 0], [3, 0, 25]]);
        $this->insertEntry('2026-09-08', 'purchase_receipt', [[1, 999, 0]], ['currency' => 'دولار']);
        $this->insertEntry('2026-10-01', 'purchase_receipt', [[1, 999, 0]]);

        $filters = ['from_date' => '2026-09-01', 'to_date' => '2026-09-30', 'currency' => 'شيكل'];
        $events = [
            $this->event('sale', 'revenue', 0, 900, ['is_sales_revenue' => true, 'source_type' => 'instant_sale', 'source_id' => 7]),
            $this->event('return', 'sales_returns', 50, 0),
            $this->event('cost', 'cost', 500, 25),
            $this->event('other', 'revenue', 0, 10),
            $this->event('salary', 'expense', 20, 0),
        ];
        $builder = app(FinancialAccountingReportBuilder::class);
        $service = app(IncomeStatementDetailsService::class);
        $income = $builder->income($events, $filters);
        $rows = collect($service->build($income, $events, $filters, true)['rows'])->keyBy('label');

        $this->assertSame(1000.0, $rows['بضاعة أول المدة']['debit']);
        $this->assertSame(765.0, $rows['بضاعة آخر المدة']['credit']);
        $this->assertSame(300.0, $rows['المشتريات']['debit']);
        $this->assertSame(40.0, $rows['مردودات المشتريات']['credit']);
        $this->assertSame(20.0, $rows['تسويات وحركات مخزون أخرى']['credit']);
        $this->assertArrayNotHasKey('فرق المطابقة مع تكلفة المبيعات المسجّلة', $rows->all());
        $this->assertSame(475.0, $rows['مجموع تكلفة المبيعات']['balance']);
        $this->assertSame(1000.0, $rows['المبيعات قبل الخصم']['credit']);
        $this->assertSame(100.0, $rows['خصم مسموح به']['debit']);
        $this->assertSame(850.0, $rows['صافي المبيعات']['credit']);
        $this->assertSame(10.0, $rows['مجموع الإيرادات الأخرى']['credit']);
        $this->assertSame(365.0, $income['summary']['net_profit']);

        $crossCutover = array_replace($filters, ['from_date' => '2026-08-01']);
        $rows = collect($service->build($income, $events, $crossCutover, true)['rows'])->keyBy('label');
        $this->assertNull($rows['بضاعة أول المدة']['balance']);
        $this->assertNull($rows['المشتريات']['balance']);
        $this->assertSame(765.0, $rows['بضاعة آخر المدة']['balance']);

        $this->insertEntry('2026-10-01', 'instant_sale', [[1, 0, 500], [3, 500, 0]], ['source_id' => 7]);
        $rows = collect($service->build($income, $events, $filters, true)['rows'])->keyBy('label');
        $this->assertNull($rows['خصم مسموح به']['balance']);
        $this->assertSame(900.0, $rows['المبيعات بعد الخصم']['credit']);
        $this->assertSame(850.0, $rows['صافي المبيعات']['credit']);
    }

    private function insertEntry(string $date, string $source, array $lines, array $attributes = []): int
    {
        $id = DB::table('accounting_journal_entries')->insertGetId($attributes + [
            'entry_date' => $date, 'source_type' => $source, 'currency' => 'شيكل', 'status' => 'posted',
        ]);
        foreach ($lines as [$account, $debit, $credit]) {
            DB::table('accounting_journal_lines')->insert([
                'journal_entry_id' => $id, 'account_id' => $account, 'debit' => $debit, 'credit' => $credit,
            ]);
        }

        return $id;
    }

    private function event(string $key, string $section, float $debit, float $credit, array $extra = []): array
    {
        return $extra + [
            'date' => '2026-09-06', 'currency' => 'شيكل', 'section' => $section,
            'category_key' => $key, 'category_name' => $key, 'debit' => $debit, 'credit' => $credit,
        ];
    }
}
