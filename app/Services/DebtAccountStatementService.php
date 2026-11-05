<?php

namespace App\Services;

use App\Models\DebtTransaction;
use App\Models\InstantSale;
use App\Models\ProfitSale;
use App\Models\SalesReturn;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class DebtAccountStatementService
{
    public function __construct(private DebtLedgerService $ledger) {}

    /**
     * Expand net sales into their original invoice and the receipt already recorded
     * by the sale. These rows are a read model and never post money to a box or ledger.
     */
    public function rows(?int $customerId, ?int $sellerId, ?string $currency, ?string $start, ?string $end): Collection
    {
        $start = $start ? Carbon::parse($start)->toDateString() : null;
        $end = $end ? Carbon::parse($end)->toDateString() : null;
        $entries = $this->ledger->baseQuery($customerId, $sellerId)
            ->when($currency, fn ($query) => $query->where('currency', $currency))
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();

        $allocations = Schema::hasColumn('debt_transactions', 'linked_invoice_id')
            ? $entries->whereNotNull('linked_invoice_id')->groupBy('linked_invoice_id')
            : collect();
        $returns = SalesReturn::query()
            ->where('status', 'completed')
            ->whereIn('debt_transaction_id', $entries->where('source', 'sales_return')->pluck('id'))
            ->get()
            ->groupBy(fn (SalesReturn $return) => $return->instant_sale_id.'|'.$return->currency);
        $rows = collect();
        $handled = [];

        foreach ($this->sales($entries, $customerId, $sellerId) as [$source, $sale, $entry]) {
            if ($sale->isCancelled() || ! $sale->created_at) {
                continue;
            }

            $gross = round((float) $sale->total_cost, 2);
            $paid = $sale->payment_box_id
                ? round(min($gross, max(0, (float) $sale->payment_box_value)), 2)
                : 0.0;
            $net = $entry ? (float) $entry->amount : 0.0;
            // Retain legacy rows exactly when the source cannot explain the posted net.
            if ($gross <= 0 || abs(round($gross - $paid, 2) - $net) >= 0.005) {
                continue;
            }

            $saleCurrency = $entry?->currency ?: $this->ledger->normalizeCurrency($sale->paymentBox?->currency);
            if ($currency && $currency !== $saleCurrency) {
                continue;
            }

            $number = $source === 'instant_sale'
                ? ($sale->serial_number ?: 'SAL-'.str_pad((string) $sale->id, 7, '0', STR_PAD_LEFT))
                : '#'.$sale->id;
            $base = [
                'id' => $entry?->id ?? -(2 * $sale->id + ($source === 'profit_sale' ? 1 : 0)),
                'source' => $source,
                'source_id' => $sale->id,
                'source_label' => 'فاتورة '.$number,
                'currency' => $saleCurrency,
                'transaction_date' => $entry?->transaction_date?->toDateString() ?? $sale->created_at->toDateString(),
                'created_at' => $sale->created_at->toDateTimeString(),
                'receipt_images' => [],
                'sort_id' => $entry?->id ?? 0,
            ];

            if ($entry) {
                $handled[$entry->id] = true;
            }
            $formatted = $entry ? $this->ledger->formatTransaction($entry) : [];
            $rows->push(array_replace($base, $formatted, [
                'type' => 'given',
                'entry_kind' => 'sales_invoice',
                'amount' => $gross,
                'source_label' => $base['source_label'],
                'invoice_remaining' => $entry ? round(max(
                    0,
                    $net - $allocations->get($entry->id, collect())->sum('amount')
                        - ($source === 'instant_sale'
                            ? $returns->get($sale->id.'|'.$saleCurrency, collect())->sum('credit_amount')
                            : 0),
                ), 2) : 0.0,
            ]));

            if ($paid > 0) {
                $rows->push(array_replace($base, [
                    'id' => 0,
                    'type' => 'taken',
                    'entry_kind' => 'receipt',
                    'amount' => $paid,
                    'box_id' => $sale->payment_box_id,
                    'is_invoice_receipt' => true,
                ]));
            }
        }

        foreach ($entries as $entry) {
            if (! isset($handled[$entry->id])) {
                $rows->push($this->ledger->formatTransaction($entry));
            }
        }

        $balances = [];

        return $rows->sortBy([
            ['transaction_date', 'asc'],
            fn (array $a, array $b) => ($a['sort_id'] ?? $a['id']) <=> ($b['sort_id'] ?? $b['id']),
            ['created_at', 'asc'],
            fn (array $a, array $b) => ($a['source'] ?? '') <=> ($b['source'] ?? ''),
            fn (array $a, array $b) => ($a['source_id'] ?? 0) <=> ($b['source_id'] ?? 0),
            fn (array $a, array $b) => ($a['is_invoice_receipt'] ?? false) <=> ($b['is_invoice_receipt'] ?? false),
        ])->map(function (array $row) use (&$balances) {
            $currency = $row['currency'];
            $row['balance_before'] = $balances[$currency] ?? 0.0;
            $effect = $row['type'] === 'taken' ? $row['amount'] : -$row['amount'];
            $row['balance_after'] = $balances[$currency] = round($row['balance_before'] + $effect, 2);
            $row['entry_label'] = match ($row['entry_kind']) {
                'receipt' => 'سند قبض',
                'payment' => 'سند دفع',
                'sales_invoice' => 'فاتورة مبيعات',
                'purchase_invoice' => 'فاتورة شراء',
                default => $row['source_label'] ?? 'تسوية',
            };
            $row['amount_prefix'] = match ($row['entry_kind']) {
                'receipt' => '+',
                'payment' => '−',
                default => '',
            };
            unset($row['sort_id']);

            return $row;
        })->filter(fn (array $row) => (! $start || $row['transaction_date'] >= $start)
            && (! $end || $row['transaction_date'] <= $end))->values();
    }

    private function sales(Collection $entries, ?int $customerId, ?int $sellerId): iterable
    {
        foreach (['instant_sale' => InstantSale::class, 'profit_sale' => ProfitSale::class] as $source => $model) {
            $linked = $entries->where('source', $source)->where('type', 'given')->groupBy('source_id');
            $other = $source === 'instant_sale' ? 'buyer_id' : 'customer_id';
            $party = $sellerId ? 'seller_id' : $other;
            $query = $model::query()->with('paymentBox')->where(function ($query) use ($model, $source, $linked, $party, $sellerId, $customerId) {
                $query->whereIn('id', $linked->keys())->orWhere(function ($query) use ($model, $source, $party, $sellerId, $customerId) {
                    $query->where($party, $sellerId ?: $customerId)->whereColumn('payment_box_value', '>=', 'total_cost');
                    $query->whereNotExists(DebtTransaction::query()
                        ->where('source', $source)
                        ->whereColumn('source_id', (new $model)->qualifyColumn('id'))
                        ->whereNull('deleted_at')->selectRaw('1'));
                    if (! $sellerId) {
                        $query->whereNull('seller_id');
                    }
                });
            });

            if ($source === 'instant_sale') {
                $query->whereNull('parent_id')->whereNull('maintenance_id');
            }

            foreach ($query->get() as $sale) {
                $matches = $linked->get($sale->id, collect());
                if ($matches->count() <= 1) {
                    yield [$source, $sale, $matches->first()];
                }
            }
        }
    }

    public static function balanceLabel(float $balance): string
    {
        if (abs($balance) < 0.005) {
            return 'مسدّد';
        }

        return $balance > 0 ? 'عليك' : 'لك';
    }

    public function returnCredit(DebtTransaction $invoice, Collection $entries): float
    {
        if ($invoice->source !== 'instant_sale') {
            return 0.0;
        }

        return (float) SalesReturn::query()
            ->where('instant_sale_id', $invoice->source_id)
            ->where('currency', $invoice->currency)
            ->where('status', 'completed')
            ->whereIn('debt_transaction_id', $entries->where('source', 'sales_return')->pluck('id'))
            ->sum('credit_amount');
    }
}
