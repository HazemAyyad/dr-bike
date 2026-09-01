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

        $item = $method->invoke(new Reports(), 'sales', 150.0, 100.0);

        $this->assertSame('sales', $item['key']);
        $this->assertSame(150.0, $item['value']);
        $this->assertSame(100.0, $item['previous_value']);
        $this->assertSame(50.0, $item['change_percent']);
    }

    public function test_summary_item_handles_empty_previous_period(): void
    {
        $method = new ReflectionMethod(Reports::class, 'analyticsSummaryItem');
        $method->setAccessible(true);

        $this->assertSame(0.0, $method->invoke(new Reports(), 'sales', 0.0, 0.0)['change_percent']);
        $this->assertSame(100.0, $method->invoke(new Reports(), 'sales', 20.0, 0.0)['change_percent']);
    }

    public function test_net_profit_subtracts_all_line_costs_and_expenses(): void
    {
        $method = new ReflectionMethod(Reports::class, 'analyticsNetProfit');
        $method->setAccessible(true);

        $this->assertSame(350.0, $method->invoke(new Reports(), 1000.0, 500.0, 150.0));
    }

    public function test_line_cost_prefers_fifo_snapshot_and_falls_back_to_wholesale_price(): void
    {
        $method = new ReflectionMethod(Reports::class, 'analyticsLineCost');
        $method->setAccessible(true);

        $this->assertSame(75.0, $method->invoke(new Reports(), 75.0, 3.0, 20.0));
        $this->assertSame(60.0, $method->invoke(new Reports(), null, 3.0, 20.0));
    }
}
