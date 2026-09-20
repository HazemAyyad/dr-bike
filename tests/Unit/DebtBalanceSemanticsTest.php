<?php

namespace Tests\Unit;

use App\Services\DebtLedgerService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DebtBalanceSemanticsTest extends TestCase
{
    #[DataProvider('balances')]
    public function test_negative_is_receivable_and_positive_is_payable(float $balance, float $receivable, float $payable): void
    {
        $this->assertSame(
            ['receivable' => $receivable, 'payable' => $payable],
            DebtLedgerService::classifySignedBalance($balance),
        );
    }

    public static function balances(): array
    {
        return [
            'customer owes shop' => [-125.5, 125.5, 0.0],
            'shop owes party' => [80.0, 0.0, 80.0],
            'settled' => [0.0, 0.0, 0.0],
        ];
    }

    #[DataProvider('movements')]
    public function test_movement_is_split_when_it_crosses_zero(
        float $before,
        float $amount,
        string $type,
        array $expected,
    ): void {
        $this->assertSame(
            $expected,
            DebtLedgerService::allocateSignedMovement($before, $amount, $type),
        );
    }

    public static function movements(): array
    {
        return [
            'collection reduces receivable only' => [-100.0, 40.0, 'taken', [
                'receivable_debit' => 0.0,
                'receivable_credit' => 40.0,
                'payable_debit' => 0.0,
                'payable_credit' => 0.0,
            ]],
            'collection crosses to payable' => [-30.0, 50.0, 'taken', [
                'receivable_debit' => 0.0,
                'receivable_credit' => 30.0,
                'payable_debit' => 0.0,
                'payable_credit' => 20.0,
            ]],
            'payment reduces payable only' => [100.0, 40.0, 'given', [
                'receivable_debit' => 0.0,
                'receivable_credit' => 0.0,
                'payable_debit' => 40.0,
                'payable_credit' => 0.0,
            ]],
            'payment crosses to receivable' => [30.0, 50.0, 'given', [
                'receivable_debit' => 20.0,
                'receivable_credit' => 0.0,
                'payable_debit' => 30.0,
                'payable_credit' => 0.0,
            ]],
        ];
    }
}
