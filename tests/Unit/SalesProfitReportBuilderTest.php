<?php

namespace Tests\Unit;

use App\Services\SalesProfitReportBuilder;
use PHPUnit\Framework\TestCase;

class SalesProfitReportBuilderTest extends TestCase
{
    public function test_return_revenue_is_distributed_across_all_returned_lines(): void
    {
        $report = (new SalesProfitReportBuilder)->document(['sales_total' => -90.0, 'discount' => 0.0], [
            ['gross' => -30.0, 'quantity' => 1.0, 'cost_total' => -10.0],
            ['gross' => -60.0, 'quantity' => 2.0, 'cost_total' => -40.0],
        ]);
        $this->assertSame([-30.0, -60.0], array_column($report['lines'], 'sales_total'));
        $this->assertSame([-20.0, -20.0], array_column($report['lines'], 'profit'));
        $this->assertSame(-40.0, $report['profit']);
    }

    public function test_discount_rounding_never_assigns_revenue_to_a_bonus_line(): void
    {
        $report = (new SalesProfitReportBuilder)->document(['sales_total' => 9.99, 'discount' => 0.01], [
            ['gross' => 1.0, 'quantity' => 1.0, 'cost_total' => 0.1],
            ['gross' => 2.0, 'quantity' => 1.0, 'cost_total' => 0.2],
            ['gross' => 7.0, 'quantity' => 1.0, 'cost_total' => 0.7],
            ['gross' => 0.0, 'quantity' => 1.0, 'cost_total' => 1.0],
        ]);
        $this->assertSame(0.0, $report['lines'][3]['sales_total']);
        $this->assertSame(-1.0, $report['lines'][3]['profit']);
        $this->assertEqualsWithDelta(9.99, array_sum(array_column($report['lines'], 'sales_total')), 0.000001);
        $this->assertEqualsWithDelta($report['profit'], array_sum(array_column($report['lines'], 'profit')), 0.000001);
    }
}
