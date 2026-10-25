<?php

namespace Tests\Unit;

use App\Services\SalesAccountingReportBuilder;
use PHPUnit\Framework\TestCase;

class SalesAccountingReportBuilderTest extends TestCase
{
    private function sale(string $key, float $total, string $date = '2026-10-01', float $discount = 0): array
    {
        return ['person_key' => $key, 'person_name' => 'نفس الاسم', 'person_type' => 'customer', 'date' => $date, 'total' => $total, 'discount' => $discount];
    }

    public function test_groups_accounts_by_identity_and_does_not_subtract_persisted_discount_twice(): void
    {
        $report = (new SalesAccountingReportBuilder)->build([
            $this->sale('customer:1', 90, discount: 10), $this->sale('customer:1', 110), $this->sale('seller:1', 800),
        ], [$this->sale('customer:1', 50, discount: 5)], 'customer', true);

        $this->assertCount(2, $report['rows']);
        $row = $report['rows'][1];
        $this->assertSame('customer:1', $row['key']);
        $this->assertSame(210.0, $row['gross_sales']);
        $this->assertSame(200.0, $row['sales_total']);
        $this->assertSame(150.0, $row['net_sales']);
        $this->assertSame(20.0, $row['sales_share']);
        $this->assertSame(25.0, $row['return_rate']); // 50 / 200, NOT 50 / 1000.
        $this->assertSame(5.0, $row['return_discount']); // Informational; already in refund.
        $this->assertSame(950.0, $report['summary']['net_sales']);
    }

    public function test_months_keep_the_year_and_returns_use_their_own_completion_date(): void
    {
        $report = (new SalesAccountingReportBuilder)->build([
            $this->sale('customer:1', 100, '2025-01-31'), $this->sale('customer:2', 300, '2026-01-01'),
        ], [$this->sale('customer:1', 150, '2026-02-01')], 'month', true, 'label');

        $this->assertSame(['2025-01', '2026-01', '2026-02'], array_column($report['rows'], 'key'));
        $this->assertSame(25.0, $report['rows'][0]['sales_share']);
        $this->assertNull($report['rows'][2]['return_rate']);
        $this->assertSame(-150.0, $report['rows'][2]['net_sales']);
        $this->assertSame(37.5, $report['summary']['return_rate']);
    }

    public function test_daily_grouping_combines_accounts_and_hides_returns_when_disabled(): void
    {
        $sales = [$this->sale('customer:1', 100), $this->sale('customer:2', 200), $this->sale('customer:1', 50, '2026-10-02')];
        $report = (new SalesAccountingReportBuilder)->build($sales, [$this->sale('customer:3', 10, '2026-10-03')], 'day', false, 'label');

        $this->assertCount(2, $report['rows']);
        $this->assertSame(300.0, $report['rows'][0]['sales_total']);
        $this->assertSame(0.0, $report['summary']['returns_total']);
        $this->assertSame(350.0, $report['summary']['net_sales']);
        $this->assertSame(100.0, $report['summary']['sales_share']);
    }

    public function test_missing_refund_discount_and_empty_denominators_are_not_fabricated(): void
    {
        $return = $this->sale('customer:1', 50);
        $return['discount'] = null;
        $report = (new SalesAccountingReportBuilder)->build([], [$return], 'customer', true);
        $this->assertNull($report['rows'][0]['return_discount']);
        $this->assertNull($report['summary']['return_discount']);
        $this->assertNull($report['summary']['return_rate']);
        $this->assertNull($report['summary']['sales_share']);
        $empty = (new SalesAccountingReportBuilder)->build([], [], 'customer', false);
        $this->assertSame([], $empty['rows']);
        $this->assertSame(0.0, $empty['summary']['net_sales']);
    }
}
