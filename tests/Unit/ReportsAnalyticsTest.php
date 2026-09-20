<?php

namespace Tests\Unit;

use App\Http\Controllers\API\Reports;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ReportsAnalyticsTest extends TestCase
{
    public function test_summary_item_calculates_change_from_previous_period(): void
    {
        $method = new ReflectionMethod(Reports::class, 'analyticsSummaryItem');
        $method->setAccessible(true);

        $item = $method->invoke(new Reports, 'sales', 150.0, 100.0);

        $this->assertSame('sales', $item['key']);
        $this->assertSame(150.0, $item['value']);
        $this->assertSame(100.0, $item['previous_value']);
        $this->assertSame(50.0, $item['change_percent']);
    }

    public function test_summary_item_handles_empty_previous_period(): void
    {
        $method = new ReflectionMethod(Reports::class, 'analyticsSummaryItem');
        $method->setAccessible(true);

        $this->assertSame(0.0, $method->invoke(new Reports, 'sales', 0.0, 0.0)['change_percent']);
        $this->assertSame(100.0, $method->invoke(new Reports, 'sales', 20.0, 0.0)['change_percent']);
    }

    public function test_net_profit_subtracts_all_line_costs_and_expenses(): void
    {
        $method = new ReflectionMethod(Reports::class, 'analyticsNetProfit');
        $method->setAccessible(true);

        $this->assertSame(350.0, $method->invoke(new Reports, 1000.0, 500.0, 150.0));
    }

    public function test_line_cost_uses_only_authoritative_fifo_snapshot(): void
    {
        $method = new ReflectionMethod(Reports::class, 'analyticsLineCost');
        $method->setAccessible(true);

        $this->assertSame(75.0, $method->invoke(new Reports, 75.0));
        $this->assertSame(0.0, $method->invoke(new Reports, null));
    }

    public function test_net_sales_does_not_subtract_discount_already_in_stored_total(): void
    {
        $method = new ReflectionMethod(Reports::class, 'financialNetSales');
        $method->setAccessible(true);

        // A 100 invoice with a 10 discount is persisted as total_cost = 90.
        $this->assertSame(90.0, $method->invoke(new Reports, 90.0, 0.0));
        $this->assertSame(75.0, $method->invoke(new Reports, 90.0, 15.0));
    }

    public function test_product_revenue_allocates_the_persisted_discount_once(): void
    {
        $method = new ReflectionMethod(Reports::class, 'allocatedProductRevenue');
        $method->setAccessible(true);

        $this->assertSame(54.0, $method->invoke(new Reports, 90.0, 10.0, 60.0));
    }

    public function test_report_currency_normalizes_legacy_english_values(): void
    {
        $method = new ReflectionMethod(Reports::class, 'reportCurrency');
        $method->setAccessible(true);

        $this->assertSame('دولار', $method->invoke(new Reports, 'dollar'));
        $this->assertSame('دينار', $method->invoke(new Reports, 'JOD'));
        $this->assertSame('شيكل', $method->invoke(new Reports, 'NIS'));
    }
}
