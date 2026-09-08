<?php

namespace App\Support;

final class SalesOrderSettlementBreakdown
{
    /** @return array{gross: float, carrier_fee: float, cash: float} */
    public static function from(float $gross, float $carrierFee): array
    {
        $gross = round($gross, 2);
        $carrierFee = round($carrierFee, 2);

        return [
            'gross' => $gross,
            'carrier_fee' => $carrierFee,
            'cash' => round($gross - $carrierFee, 2),
        ];
    }
}
