<?php

namespace Tests\Unit;

use App\Support\SalesOrderSettlementBreakdown;
use PHPUnit\Framework\TestCase;

class SalesOrderSettlementBreakdownTest extends TestCase
{
    public function test_carrier_fee_is_kept_out_of_the_cash_drawer(): void
    {
        $amounts = SalesOrderSettlementBreakdown::from(120, 20);

        $this->assertSame(120.0, $amounts['gross']);
        $this->assertSame(20.0, $amounts['carrier_fee']);
        $this->assertSame(100.0, $amounts['cash']);
    }

    public function test_no_carrier_fee_means_the_full_settlement_is_cash(): void
    {
        $amounts = SalesOrderSettlementBreakdown::from(120, 0);

        $this->assertSame(120.0, $amounts['cash']);
    }

    public function test_values_are_rounded_as_money_before_subtraction(): void
    {
        $amounts = SalesOrderSettlementBreakdown::from(100.005, 12.004);

        $this->assertSame(100.01, $amounts['gross']);
        $this->assertSame(12.0, $amounts['carrier_fee']);
        $this->assertSame(88.01, $amounts['cash']);
    }
}
