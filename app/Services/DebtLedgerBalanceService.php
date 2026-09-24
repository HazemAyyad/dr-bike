<?php

namespace App\Services;

use App\Models\DebtTransaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DebtLedgerBalanceService
{
    public function recalculatePersonCurrencyBalances(
        ?int $customerId,
        ?int $sellerId,
        string $currency,
        bool $quiet = false,
    ): int {
        $running = 0.0;
        $updated = 0;

        $this->activePersonCurrencyQuery($customerId, $sellerId, $currency)
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->when(DB::transactionLevel() > 0, fn ($query) => $query->lockForUpdate())
            ->get()
            ->each(function (DebtTransaction $transaction) use (&$running, &$updated, $quiet) {
                $amount = round((float) $transaction->amount, 2);
                $running = round($transaction->type === 'taken'
                    ? $running + $amount
                    : $running - $amount, 2);

                if (abs((float) $transaction->balance_after - $running) <= 0.0049) {
                    return;
                }

                if ($quiet) {
                    DB::table('debt_transactions')
                        ->where('id', $transaction->id)
                        ->update(['balance_after' => $running]);
                } else {
                    $transaction->update(['balance_after' => $running]);
                }
                $updated++;
            });

        return $updated;
    }

    /** @return Collection<int, array<string, mixed>> */
    public function inspect(): Collection
    {
        $issues = collect();
        $groups = DebtTransaction::query()
            ->active()
            ->select(['customer_id', 'seller_id', 'currency'])
            ->distinct()
            ->get();

        foreach ($groups as $group) {
            $running = 0.0;
            $currency = $this->normalizeCurrency($group->currency);
            $this->activePersonCurrencyQuery($group->customer_id, $group->seller_id, $currency)
                ->orderBy('transaction_date')
                ->orderBy('id')
                ->get()
                ->each(function (DebtTransaction $transaction) use (&$running, $issues, $currency) {
                    $amount = round((float) $transaction->amount, 2);
                    $running = round($transaction->type === 'taken'
                        ? $running + $amount
                        : $running - $amount, 2);
                    $stored = round((float) $transaction->balance_after, 2);
                    if (abs($stored - $running) <= 0.0049) {
                        return;
                    }
                    $issues->push([
                        'person_type' => $transaction->customer_id ? 'customer' : 'seller',
                        'person_id' => (int) ($transaction->customer_id ?: $transaction->seller_id),
                        'customer_id' => $transaction->customer_id ? (int) $transaction->customer_id : null,
                        'seller_id' => $transaction->seller_id ? (int) $transaction->seller_id : null,
                        'currency' => $currency,
                        'transaction_date' => $transaction->transaction_date?->toDateString(),
                        'transaction_id' => (int) $transaction->id,
                        'stored_balance' => $stored,
                        'expected_balance' => $running,
                        'difference' => round($stored - $running, 2),
                    ]);
                });
        }

        return $issues;
    }

    private function activePersonCurrencyQuery(?int $customerId, ?int $sellerId, string $currency)
    {
        return DebtTransaction::query()
            ->active()
            ->where('currency', $this->normalizeCurrency($currency))
            ->when(
                $customerId,
                fn ($query) => $query->where('customer_id', $customerId)->whereNull('seller_id'),
                fn ($query) => $query->where('seller_id', $sellerId)->whereNull('customer_id'),
            );
    }

    private function normalizeCurrency(?string $currency): string
    {
        $value = trim((string) $currency);

        return in_array($value, DebtLedgerService::CURRENCIES, true) ? $value : 'شيكل';
    }
}
