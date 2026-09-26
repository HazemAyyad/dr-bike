<?php

namespace App\Services;

use App\Models\DebtTransaction;
use App\Models\PurchasePayment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class PurchasePaymentSourceIdentityService
{
    /** @return Collection<int, array<string, mixed>> */
    public function inspect(): Collection
    {
        if (! Schema::hasTable('purchase_payments') || ! Schema::hasTable('debt_transactions')) {
            return collect();
        }

        return PurchasePayment::query()
            ->whereNotNull('debt_transaction_id')
            ->orderBy('id')
            ->get()
            ->map(fn (PurchasePayment $payment) => $this->inspectPayment($payment));
    }

    /** @return array<string, mixed> */
    public function run(bool $repair = false): array
    {
        $items = $this->inspect();
        $repaired = 0;

        if ($repair) {
            foreach ($items->where('status', 'SAFE_TO_REPAIR') as $item) {
                DB::transaction(function () use ($item, &$repaired) {
                    $payment = PurchasePayment::query()->lockForUpdate()->find($item['purchase_payment_id']);
                    $transaction = $payment?->debt_transaction_id
                        ? DebtTransaction::query()->lockForUpdate()->find($payment->debt_transaction_id)
                        : null;
                    if (! $payment || ! $transaction) {
                        return;
                    }

                    $fresh = $this->inspectPayment($payment, $transaction);
                    if ($fresh['status'] !== 'SAFE_TO_REPAIR') {
                        return;
                    }

                    DB::table('debt_transactions')
                        ->where('id', $transaction->id)
                        ->update(['source_id' => $payment->id]);
                    Log::notice('purchase_payment_debt_source_id_repaired', [
                        'purchase_payment_id' => (int) $payment->id,
                        'debt_transaction_id' => (int) $transaction->id,
                        'source' => $transaction->source,
                        'old_source_id' => $transaction->source_id,
                        'new_source_id' => (int) $payment->id,
                    ]);
                    $repaired++;
                }, 3);
            }
        }

        $remaining = $repair ? $this->inspect() : $items;

        return [
            'dry_run' => ! $repair,
            'items' => $items->values()->all(),
            'summary' => [
                'total' => $items->count(),
                'safe_to_repair' => $items->where('status', 'SAFE_TO_REPAIR')->count(),
                'ambiguous' => $items->where('status', 'AMBIGUOUS')->count(),
                'already_correct' => $items->where('status', 'ALREADY_CORRECT')->count(),
                'repaired' => $repaired,
                'remaining_safe_to_repair' => $remaining->where('status', 'SAFE_TO_REPAIR')->count(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function inspectPayment(PurchasePayment $payment, ?DebtTransaction $transaction = null): array
    {
        $transaction ??= $payment->debt_transaction_id
            ? DebtTransaction::query()->find($payment->debt_transaction_id)
            : null;
        $expectedSource = $this->expectedSource($payment);
        $identityConflict = DebtTransaction::query()
            ->active()
            ->where('source', $expectedSource)
            ->where('source_id', $payment->id)
            ->when($transaction, fn ($query) => $query->where('id', '!=', $transaction->id))
            ->exists();
        $mismatchReasons = $this->mismatchReasons($payment, $transaction, $expectedSource, $identityConflict);
        $status = $mismatchReasons === []
            ? 'ALREADY_CORRECT'
            : ($mismatchReasons === ['source_id_mismatch'] ? 'SAFE_TO_REPAIR' : 'AMBIGUOUS');

        return [
            'purchase_payment_id' => (int) $payment->id,
            'bill_id' => $payment->bill_id ? (int) $payment->bill_id : null,
            'debt_transaction_id' => $payment->debt_transaction_id ? (int) $payment->debt_transaction_id : null,
            'current_source' => $transaction?->source,
            'current_source_id' => $transaction?->source_id ? (int) $transaction->source_id : null,
            'expected_source' => $expectedSource,
            'expected_source_id' => (int) $payment->id,
            'identity_conflict' => $identityConflict,
            'status' => $status,
            'mismatch_reasons' => $mismatchReasons,
        ];
    }

    /** @return array<int, string> */
    private function mismatchReasons(
        PurchasePayment $payment,
        ?DebtTransaction $transaction,
        string $expectedSource,
        bool $identityConflict,
    ): array {
        if (! $transaction) {
            return array_values(array_filter([
                'missing_debt_transaction',
                $identityConflict ? 'identity_conflict' : null,
            ]));
        }

        $reasons = [];
        if ((string) $transaction->source !== $expectedSource) {
            $reasons[] = 'source_type_mismatch';
        }
        if ((int) ($transaction->source_id ?? 0) !== (int) $payment->id) {
            $reasons[] = 'source_id_mismatch';
        }
        if ($identityConflict) {
            $reasons[] = 'identity_conflict';
        }
        if (abs((float) $transaction->amount - (float) $payment->amount) > 0.0001) {
            $reasons[] = 'amount_mismatch';
        }
        if ((int) ($transaction->customer_id ?? 0) !== (int) ($payment->customer_id ?? 0)) {
            $reasons[] = 'customer_mismatch';
        }
        if ((int) ($transaction->seller_id ?? 0) !== (int) ($payment->seller_id ?? 0)) {
            $reasons[] = 'seller_mismatch';
        }
        if ((int) ($transaction->box_id ?? 0) !== (int) ($payment->box_id ?? 0)) {
            $reasons[] = 'box_mismatch';
        }
        if ($this->normalizeCurrency($transaction->currency) !== $this->normalizeCurrency($payment->currency)) {
            $reasons[] = 'currency_mismatch';
        }
        if (! $payment->paid_at
            || ! $transaction->transaction_date
            || $payment->paid_at->toDateString() !== $transaction->transaction_date->toDateString()) {
            $reasons[] = 'payment_date_mismatch';
        }
        if ($transaction->archived_at || $transaction->deleted_at) {
            $reasons[] = 'transaction_inactive';
        }

        return $reasons;
    }

    private function expectedSource(PurchasePayment $payment): string
    {
        return match ((string) $payment->type) {
            'initial_payment' => 'purchase_initial_payment',
            'account_payment' => 'purchase_account_payment',
            default => 'purchase_payment',
        };
    }

    private function normalizeCurrency(?string $currency): string
    {
        $currency = trim((string) $currency);

        return in_array($currency, DebtLedgerService::CURRENCIES, true) ? $currency : 'شيكل';
    }
}
