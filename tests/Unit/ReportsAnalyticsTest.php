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
}
