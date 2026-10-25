<?php

namespace Tests\Unit;

use App\Services\FinancialAccountingReportBuilder;
use PHPUnit\Framework\TestCase;

class FinancialAccountingReportBuilderTest extends TestCase
{
    public function test_cost_subaccounts_stay_out_of_operating_expenses(): void
    {
        $accounts = [
            1 => ['parent_id' => null, 'system_key' => 'cost_of_goods_sold'],
            2 => ['parent_id' => 1, 'system_key' => null],
            3 => ['parent_id' => 4, 'system_key' => null],
            4 => ['parent_id' => 3, 'system_key' => null],
        ];
        $builder = new FinancialAccountingReportBuilder;
        $this->assertSame('cost', $builder->sectionForAccount('expense', 2, $accounts));
        $this->assertSame('expense', $builder->sectionForAccount('expense', 3, $accounts));
        $this->assertSame('revenue', $builder->sectionForAccount('revenue', 2, $accounts));
        $this->assertSame('sales_returns', $builder->sectionForAccount('contra_revenue', 2, $accounts));
    }

    private function event(string $key, string $date, float $debit, float $credit = 0, array $extra = []): array
    {
        return array_merge([
            'key' => $key, 'date' => $date, 'section' => 'expense', 'category_key' => 'electricity',
            'category_name' => 'الكهرباء', 'debit' => $debit, 'credit' => $credit, 'currency' => 'شيكل', 'box_id' => 1,
        ], $extra);
    }

    private function filters(array $extra = []): array
    {
        return array_merge(['from_date' => '2026-09-01', 'to_date' => '2026-09-30', 'currency' => 'شيكل', 'category_key' => '', 'box_id' => '', 'include_opening' => false], $extra);
    }

    public function test_projection_wins_without_discarding_the_dated_reversal(): void
    {
        $builder = new FinancialAccountingReportBuilder;
        $rows = $builder->merge([
            $this->event('journal:1', '2026-08-30', 100),
            $this->event('journal:2', '2026-09-10', 0, 100),
        ], [
            $this->event('expense:5', '2026-08-30', 100, extra: ['covered_by' => ['expense:5']]),
            $this->event('expense:6', '2026-09-12', 40, extra: ['covered_by' => ['expense:6']]),
        ], ['expense:5' => true]);
        $this->assertSame(['journal:1', 'journal:2', 'expense:6'], array_column($rows, 'key'));
        $report = $builder->expenses($rows, $this->filters(['include_opening' => true]));
        $this->assertSame(100.0, $report['summary']['opening_balance']);
        $this->assertSame(-60.0, $report['summary']['period_total']);
        $this->assertSame(40.0, $report['summary']['closing_balance']);
    }

    public function test_opening_uses_the_same_category_box_and_currency_and_is_not_period_expense(): void
    {
        $events = [
            $this->event('opening', '2026-08-31', 100),
            $this->event('first', '2026-09-01', 50),
            $this->event('refund', '2026-09-30', 0, 10),
            $this->event('future', '2026-10-01', 900),
            $this->event('other-box', '2026-08-31', 800, extra: ['box_id' => 2]),
            $this->event('other-currency', '2026-08-31', 700, extra: ['currency' => 'دولار']),
            $this->event('other-category', '2026-09-02', 600, extra: ['category_key' => 'rent']),
            $this->event('cogs', '2026-09-02', 500, extra: ['section' => 'cost']),
        ];
        $builder = new FinancialAccountingReportBuilder;
        $filters = $this->filters(['include_opening' => true, 'category_key' => 'electricity', 'box_id' => 1]);
        $report = $builder->expenses($events, $filters);
        $this->assertSame(['first', 'refund'], array_column($report['rows'], 'key'));
        $this->assertSame(100.0, $report['summary']['opening_balance']);
        $this->assertSame(40.0, $report['summary']['period_total']);
        $this->assertSame(140.0, $report['summary']['closing_balance']);
        $this->assertSame([150.0, 140.0], array_column($report['rows'], 'balance'));
        $without = $builder->expenses($events, array_merge($filters, ['include_opening' => false]));
        $this->assertSame(0.0, $without['summary']['opening_balance']);
        $this->assertSame(40.0, $without['summary']['closing_balance']);
    }

    public function test_income_breakdown_matches_the_expense_statement_period_total(): void
    {
        $events = [
            $this->event('previous', '2026-08-31', 10000),
            $this->event('revenue', '2026-09-01', 0, 1000, ['section' => 'revenue', 'category_key' => 'sales', 'category_name' => 'مبيعات بعد الخصم']),
            $this->event('returns', '2026-09-02', 50, extra: ['section' => 'sales_returns']),
            $this->event('cost', '2026-09-01', 500, extra: ['section' => 'cost']),
            $this->event('salary', '2026-09-30', 100, extra: ['category_key' => 'salary', 'category_name' => 'الرواتب والأجور']),
            $this->event('electricity', '2026-09-15', 40),
            $this->event('adjustment', '2026-09-20', 0, 10),
            $this->event('foreign', '2026-09-20', 9999, extra: ['currency' => 'دولار']),
        ];
        $builder = new FinancialAccountingReportBuilder;
        $income = $builder->income($events, $this->filters());
        $expenses = $builder->expenses($events, $this->filters(['include_opening' => true]));
        $this->assertSame(950.0, $income['summary']['net_revenue']);
        $this->assertSame(450.0, $income['summary']['gross_profit']);
        $this->assertSame(130.0, $income['summary']['expenses']);
        $this->assertSame($expenses['summary']['period_total'], $income['summary']['expenses']);
        $this->assertSame(320.0, $income['summary']['net_profit']);
        $this->assertCount(2, array_filter($income['rows'], fn ($row) => $row['section'] === 'expense'));
    }

    public function test_unknown_historical_cost_does_not_produce_an_invented_profit(): void
    {
        $report = (new FinancialAccountingReportBuilder)->income([
            $this->event('revenue', '2026-09-10', 0, 1000, ['section' => 'revenue']),
            $this->event('cost', '2026-09-10', 100, extra: ['section' => 'cost', 'cost_complete' => false]),
            $this->event('expense', '2026-09-10', 50),
        ], $this->filters());
        $this->assertSame(100.0, $report['summary']['cost_known']);
        $this->assertNull($report['summary']['cost']);
        $this->assertNull($report['summary']['gross_profit']);
        $this->assertNull($report['summary']['net_profit']);
        $this->assertSame(50.0, $report['summary']['expenses']);
        $this->assertNull($report['rows'][1]['balance']);
    }

    public function test_empty_and_opening_only_statements_still_return_the_closing_balance(): void
    {
        $builder = new FinancialAccountingReportBuilder;
        $empty = $builder->expenses([], $this->filters());
        $this->assertSame(0, $empty['summary']['movement_count']);
        $this->assertSame(0.0, $empty['summary']['closing_balance']);
        $opening = $builder->expenses([$this->event('old', '2026-08-01', 70)], $this->filters(['include_opening' => true]));
        $this->assertSame([], $opening['rows']);
        $this->assertSame(0.0, $opening['summary']['period_total']);
        $this->assertSame(70.0, $opening['summary']['closing_balance']);
    }
}
