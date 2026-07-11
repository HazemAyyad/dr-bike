<?php

namespace App\Services;

use App\Http\Controllers\API\BoxLogs;
use App\Models\Box;
use App\Models\Customer;
use App\Models\ContactCategoryAssignment;
use App\Models\DebtTransaction;
use App\Models\IncomingCheck;
use App\Models\InstantSale;
use App\Models\Log;
use App\Models\OutgoingCheck;
use App\Models\ProfitSale;
use App\Models\SalesOrder;
use App\Models\Seller;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class DebtLedgerService
{
    public const CURRENCIES = ['شيكل', 'دولار', 'دينار'];

    public function __construct(private ?DebtLedgerActivityLogger $activityLogger = null)
    {
    }

    private function activity(): DebtLedgerActivityLogger
    {
        return $this->activityLogger ?? app(DebtLedgerActivityLogger::class);
    }

    public function normalizeCurrency(?string $currency): string
    {
        $value = trim((string) $currency);

        return in_array($value, self::CURRENCIES, true) ? $value : 'شيكل';
    }

    private function ledgerSourceLabel(?string $source): string
    {
        return match ((string) $source) {
            'instant_sale' => 'بيع فوري',
            'profit_sale' => 'بيع ربحي',
            'sales_order' => 'طلبية',
            'incoming_check' => 'شيك وارد',
            'outgoing_check' => 'شيك صادر',
            'manual', '' => 'إدخال يدوي',
            default => 'مصدر آخر',
        };
    }

    private function ledgerSourceActivitySuffix(string $source, int $sourceId): string
    {
        return $this->ledgerSourceLabel($source).' (رقم السجل '.$sourceId.')';
    }

    private function incomingCheckLedgerNote(IncomingCheck $check, string $context = ''): string
    {
        $parts = ['شيك وارد'];
        if ($context !== '') {
            $parts[] = $context;
        }
        $checkNumber = trim((string) ($check->check_id ?? ''));
        if ($checkNumber !== '') {
            $parts[] = 'رقم الشيك: '.$checkNumber;
        }
        $bank = trim((string) ($check->bank_name ?? ''));
        if ($bank !== '') {
            $parts[] = 'البنك: '.$bank;
        }
        $parts[] = 'مرجع النظام: '.$check->id;

        return implode(' — ', $parts);
    }

    private function outgoingCheckLedgerNote(OutgoingCheck $check, string $context = ''): string
    {
        $parts = ['شيك صادر'];
        if ($context !== '') {
            $parts[] = $context;
        }
        $checkNumber = trim((string) ($check->check_id ?? ''));
        if ($checkNumber !== '') {
            $parts[] = 'رقم الشيك: '.$checkNumber;
        }
        $bank = trim((string) ($check->bank_name ?? ''));
        if ($bank !== '') {
            $parts[] = 'البنك: '.$bank;
        }
        $parts[] = 'مرجع النظام: '.$check->id;

        return implode(' — ', $parts);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getTransactionActivity(int $transactionId): array
    {
        return $this->activity()->getTransactionActivity($transactionId);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getPersonActivity(?int $customerId, ?int $sellerId, ?string $currency = null): array
    {
        $filterCurrency = $currency ? $this->normalizeCurrency($currency) : null;

        $ledgerActivity = $this->activity()->getPersonActivity(
            $customerId,
            $sellerId,
            80,
            $filterCurrency
        );

        $person = $customerId
            ? Customer::find($customerId)
            : ($sellerId ? Seller::find($sellerId) : null);

        if (! $person) {
            return $ledgerActivity;
        }

        $terms = collect([
            trim((string) ($person->name ?? '')),
            trim((string) ($person->phone ?? '')),
            trim((string) ($person->sub_phone ?? '')),
        ])->filter(fn ($value) => $value !== '')->unique()->values();

        if ($terms->isEmpty()) {
            return $ledgerActivity;
        }

        $appLogs = Log::query()
            ->where(function ($q) {
                $q->whereNull('is_canceled')->orWhere('is_canceled', 0);
            })
            ->where(function ($q) use ($terms) {
                foreach ($terms as $term) {
                    $q->orWhere('name', 'like', '%'.$term.'%')
                        ->orWhere('description', 'like', '%'.$term.'%');
                }
            })
            ->orderByDesc('id')
            ->limit(80)
            ->get()
            ->map(fn (Log $log) => [
                'id' => -1 * (int) $log->id,
                'action' => 'app_log',
                'action_label' => 'سجل التطبيق',
                'title' => $log->name ?? 'سجل التطبيق',
                'description' => $log->description ?? '',
                'meta' => ['type' => $log->type],
                'debt_transaction_id' => null,
                'created_by' => null,
                'created_by_name' => null,
                'created_at' => $log->created_at?->format('Y-m-d H:i:s'),
            ])
            ->all();

        return collect(array_merge($ledgerActivity, $appLogs))
            ->sortByDesc(fn ($entry) => $entry['created_at'] ?? '')
            ->take(120)
            ->values()
            ->all();
    }

    public function validatePerson(?int $customerId, ?int $sellerId): ?string
    {
        if (!$customerId && !$sellerId) {
            return __('messages.must_select_customer_or_seller');
        }

        if ($customerId && $sellerId) {
            return __('messages.must_select_either_customer_or_seller');
        }

        return null;
    }

    public function baseQuery(?int $customerId = null, ?int $sellerId = null): Builder
    {
        $query = DebtTransaction::query()->active();

        if ($customerId) {
            $query->forCustomer($customerId);
        } elseif ($sellerId) {
            $query->forSeller($sellerId);
        }

        return $query;
    }

    public function archivedQuery(?int $customerId = null, ?int $sellerId = null): Builder
    {
        $query = DebtTransaction::query()->archived();

        if ($customerId) {
            $query->forCustomer($customerId);
        } elseif ($sellerId) {
            $query->forSeller($sellerId);
        }

        return $query;
    }

    public function deletedQuery(?int $customerId = null, ?int $sellerId = null): Builder
    {
        $query = DebtTransaction::query()->deleted();

        if ($customerId) {
            $query->forCustomer($customerId);
        } elseif ($sellerId) {
            $query->forSeller($sellerId);
        }

        return $query;
    }

    public function calculateArchivedTotals(
        ?int $customerId,
        ?int $sellerId,
        ?string $currency = null
    ): array {
        return $this->calculateTotalsForQuery(
            $this->archivedQuery($customerId, $sellerId),
            $currency
        );
    }

    public function calculateDeletedTotals(
        ?int $customerId,
        ?int $sellerId,
        ?string $currency = null
    ): array {
        return $this->calculateTotalsForQuery(
            $this->deletedQuery($customerId, $sellerId),
            $currency
        );
    }

    /**
     * @return array{total_taken: float, total_given: float, balance: float}
     */
    private function calculateTotalsForQuery(Builder $query, ?string $currency = null): array
    {
        if ($currency !== null) {
            $query->where('currency', $this->normalizeCurrency($currency));
        }

        $taken = (float) (clone $query)->where('type', 'taken')->sum('amount');
        $given = (float) (clone $query)->where('type', 'given')->sum('amount');

        return [
            'total_taken' => $taken,
            'total_given' => $given,
            'balance' => $taken - $given,
        ];
    }

    /**
     * @return array<string, array{total_taken: float, total_given: float, balance: float}>
     */
    public function calculateArchivedBalancesByCurrency(?int $customerId, ?int $sellerId): array
    {
        $balances = [];
        foreach (self::CURRENCIES as $currencyCode) {
            $balances[$currencyCode] = $this->calculateArchivedTotals(
                $customerId,
                $sellerId,
                $currencyCode
            );
        }

        return $balances;
    }

    /**
     * @return array<string, array{total_taken: float, total_given: float, balance: float}>
     */
    public function calculateDeletedBalancesByCurrency(?int $customerId, ?int $sellerId): array
    {
        $balances = [];
        foreach (self::CURRENCIES as $currencyCode) {
            $balances[$currencyCode] = $this->calculateDeletedTotals(
                $customerId,
                $sellerId,
                $currencyCode
            );
        }

        return $balances;
    }

    /**
     * @param  array<int>  $transactionIds
     */
    public function archiveTransactions(array $transactionIds): int
    {
        $count = 0;

        DB::transaction(function () use ($transactionIds, &$count) {
            $transactions = DebtTransaction::query()
                ->active()
                ->whereIn('id', $transactionIds)
                ->lockForUpdate()
                ->get();

            foreach ($transactions as $transaction) {
                $this->archiveTransaction($transaction);
                $count++;
            }
        });

        return $count;
    }

    /**
     * @param  array<int>  $transactionIds
     */
    public function restoreTransactions(array $transactionIds): int
    {
        $count = 0;

        DB::transaction(function () use ($transactionIds, &$count) {
            $transactions = DebtTransaction::query()
                ->archived()
                ->whereIn('id', $transactionIds)
                ->lockForUpdate()
                ->get();

            foreach ($transactions as $transaction) {
                $this->restoreTransaction($transaction);
                $count++;
            }
        });

        return $count;
    }

    public function restoreTransaction(DebtTransaction $transaction): void
    {
        if ($transaction->deleted_at) {
            throw new \RuntimeException(__('messages.ledger_transaction_not_restorable'));
        }

        DB::transaction(function () use ($transaction) {
            $transaction->update(['archived_at' => null]);
            $transaction = $transaction->fresh(['customer', 'seller']);

            if ($this->shouldSyncBox($transaction) && $transaction->box_id) {
                $this->applyBoxMovement($transaction, (int) $transaction->box_id);
            }

            $this->recalculateBalances($transaction->customer_id, $transaction->seller_id);

            $this->activity()->logForTransaction(
                $transaction,
                'transaction_restored',
                'استعادة معاملة دفتر ديون',
                'استعادة المعاملة #'.$transaction->id.' من الأرشيف',
                ['snapshot' => $this->activity()->transactionSnapshot($transaction)]
            );
        });
    }

    public function applyDateFilter(Builder $query, ?string $startDate, ?string $endDate): Builder
    {
        if ($startDate) {
            $query->whereDate('transaction_date', '>=', $startDate);
        }

        if ($endDate) {
            $query->whereDate('transaction_date', '<=', $endDate);
        }

        return $query;
    }

    public function resolvePeriodDates(?string $period, ?string $startDate, ?string $endDate): array
    {
        $today = Carbon::today();

        return match ($period) {
            'today' => [$today->toDateString(), $today->toDateString()],
            'yesterday' => [
                $today->copy()->subDay()->toDateString(),
                $today->copy()->subDay()->toDateString(),
            ],
            'current_week' => [
                $today->copy()->startOfWeek()->toDateString(),
                $today->copy()->endOfWeek()->toDateString(),
            ],
            'last_week' => [
                $today->copy()->subWeek()->startOfWeek()->toDateString(),
                $today->copy()->subWeek()->endOfWeek()->toDateString(),
            ],
            'current_month' => [
                $today->copy()->startOfMonth()->toDateString(),
                $today->copy()->endOfMonth()->toDateString(),
            ],
            'last_month' => [
                $today->copy()->subMonth()->startOfMonth()->toDateString(),
                $today->copy()->subMonth()->endOfMonth()->toDateString(),
            ],
            'custom' => [$startDate, $endDate],
            default => [null, null],
        };
    }

    public function getPreviousBalance(?int $customerId, ?int $sellerId, ?string $currency = 'شيكل'): float
    {
        $currency = $this->normalizeCurrency($currency);

        $latest = $this->baseQuery($customerId, $sellerId)
            ->where('currency', $currency)
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->value('balance_after');

        return (float) ($latest ?? 0);
    }

    /**
     * @return array<string, float>
     */
    public function calculateBalancesByCurrency(?int $customerId, ?int $sellerId, ?string $startDate = null, ?string $endDate = null): array
    {
        $balances = [];
        foreach (self::CURRENCIES as $currency) {
            $totals = $this->calculateTotals($customerId, $sellerId, $startDate, $endDate, $currency);
            $balances[$currency] = (float) $totals['balance'];
        }

        return $balances;
    }

    public function calculateTotals(
        ?int $customerId,
        ?int $sellerId,
        ?string $startDate = null,
        ?string $endDate = null,
        ?string $currency = null
    ): array {
        $query = $this->baseQuery($customerId, $sellerId);
        $this->applyDateFilter($query, $startDate, $endDate);

        if ($currency !== null) {
            $query->where('currency', $this->normalizeCurrency($currency));
        }

        $taken = (float) (clone $query)->where('type', 'taken')->sum('amount');
        $given = (float) (clone $query)->where('type', 'given')->sum('amount');

        return [
            'total_taken' => $taken,
            'total_given' => $given,
            'balance' => $taken - $given,
        ];
    }

    public function getGlobalSummary(): array
    {
        $customerTotals = $this->summarizeLedgerBalancesForPeople(true);
        $sellerTotals = $this->summarizeLedgerBalancesForPeople(false);

        return [
            // مجاميع أرصدة العملاء/الموردين (موجب = لنا، سالب = علينا) — شيكل للعرض الرئيسي
            'total_taken_customers' => $customerTotals['receivable'],
            'total_given_customers' => $customerTotals['payable'],
            'balance_customers' => $customerTotals['receivable'] - $customerTotals['payable'],
            'customers_count' => $customerTotals['count'],
            'total_taken_sellers' => $sellerTotals['receivable'],
            'total_given_sellers' => $sellerTotals['payable'],
            'balance_sellers' => $sellerTotals['receivable'] - $sellerTotals['payable'],
            'sellers_count' => $sellerTotals['count'],
            'receivable_customers' => $customerTotals['receivable'],
            'payable_customers' => $customerTotals['payable'],
            'receivable_sellers' => $sellerTotals['receivable'],
            'payable_sellers' => $sellerTotals['payable'],
            'customers_by_currency' => $customerTotals['by_currency'] ?? [],
            'sellers_by_currency' => $sellerTotals['by_currency'] ?? [],
        ];
    }

    /**
     * @return array{receivable: float, payable: float, count: int}
     */
    private function summarizeLedgerBalancesForPeople(bool $customers): array
    {
        $isCustomers = $customers;
        $modelClass = $isCustomers ? Customer::class : Seller::class;
        $foreignKey = $isCustomers ? 'customer_id' : 'seller_id';

        $people = $modelClass::query()
            ->where('is_canceled', false)
            ->orderBy('name')
            ->get(['id']);

        $byCurrency = [];
        foreach (self::CURRENCIES as $currency) {
            $byCurrency[$currency] = ['receivable' => 0.0, 'payable' => 0.0];
        }
        $count = 0;

        foreach ($people as $person) {
            $txQuery = DebtTransaction::query()
                ->active()
                ->where($foreignKey, $person->id)
                ->whereNull($isCustomers ? 'seller_id' : 'customer_id');

            if ((clone $txQuery)->count() === 0) {
                continue;
            }

            $hasBalance = false;
            foreach (self::CURRENCIES as $currency) {
                $currencyQuery = (clone $txQuery)->where('currency', $currency);
                $taken = (float) (clone $currencyQuery)->where('type', 'taken')->sum('amount');
                $given = (float) (clone $currencyQuery)->where('type', 'given')->sum('amount');
                $balance = $taken - $given;

                if (abs($balance) <= 0.0001) {
                    continue;
                }

                $hasBalance = true;
                if ($balance > 0) {
                    $byCurrency[$currency]['receivable'] += $balance;
                } else {
                    $byCurrency[$currency]['payable'] += abs($balance);
                }
            }

            if ($hasBalance) {
                $count++;
            }
        }

        $shekel = $byCurrency['شيكل'];

        return [
            'receivable' => $shekel['receivable'],
            'payable' => $shekel['payable'],
            'count' => $count,
            'by_currency' => $byCurrency,
        ];
    }

    public function balanceBefore(DebtTransaction $transaction): float
    {
        $amount = (float) $transaction->amount;
        $after = (float) $transaction->balance_after;

        return $transaction->type === 'taken'
            ? $after - $amount
            : $after + $amount;
    }

    public function formatTransaction(DebtTransaction $transaction): array
    {
        $currency = $this->normalizeCurrency($transaction->currency);

        $source = $transaction->source ?? 'manual';

        return [
            'id' => $transaction->id,
            'type' => $transaction->type,
            'type_label' => $transaction->type === 'taken' ? 'أخذت' : 'أعطيت',
            'source_label' => $source === 'manual' || $source === '' || $source === null
                ? null
                : $this->ledgerSourceLabel($source),
            'amount' => (float) $transaction->amount,
            'currency' => $currency,
            'balance_before' => $this->balanceBefore($transaction),
            'balance_after' => (float) $transaction->balance_after,
            'note' => $transaction->note,
            'receipt_images' => $transaction->receipt_images
                ? collect($transaction->receipt_images)->map(fn ($img) => 'public/DebtsReceipts/' . $img)->values()->all()
                : [],
            'transaction_date' => $transaction->transaction_date?->format('Y-m-d'),
            'created_at' => $transaction->created_at?->format('Y-m-d H:i:s'),
            'box_id' => $transaction->box_id,
            'source' => $transaction->source,
            'archived_at' => $transaction->archived_at?->format('Y-m-d H:i:s'),
            'deleted_at' => $transaction->deleted_at?->format('Y-m-d H:i:s'),
        ];
    }

    public function createTransaction(
        array $data,
        ?int $userId = null,
        bool $applyBox = true,
        bool $logActivity = true
    ): DebtTransaction {
        return DB::transaction(function () use ($data, $userId, $applyBox, $logActivity) {
            $currency = $this->normalizeCurrency($data['currency'] ?? 'شيكل');
            $previousBalance = $this->getPreviousBalance(
                $data['customer_id'] ?? null,
                $data['seller_id'] ?? null,
                $currency
            );
            $amount = (float) $data['amount'];

            $balanceAfter = $data['type'] === 'taken'
                ? $previousBalance + $amount
                : $previousBalance - $amount;

            $transaction = DebtTransaction::create([
                'customer_id' => $data['customer_id'] ?? null,
                'seller_id' => $data['seller_id'] ?? null,
                'type' => $data['type'],
                'amount' => $amount,
                'currency' => $currency,
                'balance_after' => $balanceAfter,
                'note' => $data['note'] ?? null,
                'receipt_images' => $data['receipt_images'] ?? null,
                'transaction_date' => $data['transaction_date'],
                'box_id' => $data['box_id'] ?? null,
                'source' => $data['source'] ?? 'manual',
                'source_id' => $data['source_id'] ?? null,
                'created_by' => $userId,
            ]);

            if ($applyBox && ! empty($data['box_id'])) {
                $this->applyBoxMovement($transaction, (int) $data['box_id']);
            }

            $transaction = $transaction->fresh(['customer', 'seller']);

            if ($logActivity) {
                $typeLabel = $transaction->type === 'taken' ? 'أخذت' : 'أعطيت';
                $person = $this->activity()->personLabel($transaction->customer_id, $transaction->seller_id);
                $this->activity()->logForTransaction(
                    $transaction,
                    'transaction_created',
                    'إنشاء معاملة دفتر ديون',
                    "تسجيل {$typeLabel} بقيمة {$amount} {$currency} — {$person}",
                    ['snapshot' => $this->activity()->transactionSnapshot($transaction)]
                );
            }

            return $transaction;
        });
    }

    /**
     * Record unpaid portion of instant sale on the ledger (أعطيت = باقي الفاتورة بعد النقدي).
     */
    public function syncInstantSaleToLedger(InstantSale $sale): ?DebtTransaction
    {
        if ($sale->parent_id || $sale->isCancelled()) {
            return null;
        }

        $sale = $this->ensureInstantSalePersonForLedger($sale);

        [$customerId, $sellerId] = $this->resolveInstantSalePersonIds($sale);
        if (! $customerId && ! $sellerId) {
            $this->deleteSourceLedger('instant_sale', $sale->id);

            return null;
        }

        $sale->loadMissing('paymentBox');
        $currency = $sale->paymentBox
            ? $this->normalizeCurrency($sale->paymentBox->currency)
            : 'شيكل';

        $debtAmount = $this->instantSaleDebtAmount($sale);
        $note = 'بيع فوري #'.$sale->id;

        return $this->upsertSourceLedgerEntry(
            'instant_sale',
            $sale->id,
            $customerId,
            $sellerId,
            'given',
            $debtAmount,
            $note,
            $sale->created_at?->format('Y-m-d') ?? now()->format('Y-m-d'),
            $currency
        );
    }

    /**
     * Record sales order revenue on the debt ledger at delivery (without instant sale).
     */
    public function syncSalesOrderToLedger(
        SalesOrder $order,
        float $recognizedTotal,
        float $paidAmount
    ): ?DebtTransaction {
        $order->loadMissing('customer');
        $customerId = $order->customer_id ? (int) $order->customer_id : null;
        $sellerId = null;

        if (! $customerId && ! $sellerId) {
            $this->deleteSourceLedger('sales_order', (int) $order->id);

            return null;
        }

        $currency = 'شيكل';
        if ($order->payment_box_id) {
            $box = Box::query()->find($order->payment_box_id);
            if ($box) {
                $currency = $this->normalizeCurrency($box->currency);
            }
        }

        $serial = trim((string) ($order->serial_number ?? ''));
        $note = 'طلبية '.($serial !== '' ? $serial : '#'.$order->id);
        $transactionDate = $order->financial_posted_at?->format('Y-m-d')
            ?? now()->format('Y-m-d');

        if ($order->is_debt_collection) {
            $amount = round(max(0, min($paidAmount, $recognizedTotal)), 2);
            if ($amount <= 0.0001) {
                $this->deleteSourceLedger('sales_order', (int) $order->id);

                return null;
            }

            return $this->upsertSourceLedgerEntry(
                'sales_order',
                (int) $order->id,
                $customerId,
                $sellerId,
                'taken',
                $amount,
                $note,
                $transactionDate,
                $currency
            );
        }

        $debtAmount = max(0, round($recognizedTotal - $paidAmount, 2));

        return $this->upsertSourceLedgerEntry(
            'sales_order',
            (int) $order->id,
            $customerId,
            $sellerId,
            'given',
            $debtAmount,
            $note,
            $transactionDate,
            $currency
        );
    }

    public function syncProfitSaleToLedger(ProfitSale $sale): ?DebtTransaction
    {
        $customerId = $sale->customer_id ? (int) $sale->customer_id : null;
        $sellerId = $sale->seller_id ? (int) $sale->seller_id : null;

        if (! $customerId && ! $sellerId) {
            $this->deleteSourceLedger('profit_sale', (int) $sale->id);

            return null;
        }

        $sale->loadMissing('paymentBox');
        $currency = $sale->paymentBox
            ? $this->normalizeCurrency($sale->paymentBox->currency)
            : 'شيكل';
        $total = (float) ($sale->total_cost ?? 0);
        $paid = max(0, (float) ($sale->payment_box_value ?? 0));
        $debtAmount = max(0, round($total - $paid, 2));

        return $this->upsertSourceLedgerEntry(
            'profit_sale',
            (int) $sale->id,
            $customerId,
            $sellerId,
            'given',
            $debtAmount,
            'بيع ربحي #'.$sale->id,
            $sale->created_at?->format('Y-m-d') ?? now()->format('Y-m-d'),
            $currency
        );
    }

    /**
     * قبض شيك وارد — على من أعطانا الشيك (أخذت).
     */
    public function syncIncomingCheckReceiveToLedger(IncomingCheck $check): ?DebtTransaction
    {
        $check->loadMissing(['fromCustomer', 'fromSeller']);

        $customerId = $check->from_customer ? (int) $check->from_customer : null;
        $sellerId = $check->from_seller ? (int) $check->from_seller : null;

        if (! $customerId && ! $sellerId) {
            return null;
        }

        $currency = $this->normalizeCurrency($check->currency);
        $amount = (float) $check->total;
        $note = $this->incomingCheckReceiveLedgerNote($check);

        return $this->upsertSourceLedgerEntry(
            'incoming_check',
            $check->id,
            $customerId,
            $sellerId,
            'taken',
            $amount,
            $note,
            $check->created_at?->format('Y-m-d') ?? now()->format('Y-m-d'),
            $currency
        );
    }

    private function incomingCheckReceiveLedgerNote(IncomingCheck $check): string
    {
        $personName = trim((string) (
            $check->fromCustomer?->name
            ?? $check->fromSeller?->name
            ?? ''
        ));

        $parts = ['أخذت'];
        if ($personName !== '') {
            $parts[] = $personName.' أعطاني شيكاً';
        } else {
            $parts[] = 'قبض شيك وارد';
        }

        $checkNumber = trim((string) ($check->check_id ?? ''));
        if ($checkNumber !== '') {
            $parts[] = 'رقم الشيك: '.$checkNumber;
        }

        $amount = (float) $check->total;
        $currency = $this->normalizeCurrency($check->currency);
        $parts[] = 'بقيمة '.$amount.' '.$currency;
        $parts[] = 'مرجع النظام: '.$check->id;

        return implode(' — ', $parts);
    }

    /**
     * مزامنة شيك وارد مع دفتر الديون حسب الحالة والعملة.
     */
    public function syncIncomingCheckToLedger(IncomingCheck $check): ?DebtTransaction
    {
        if (in_array($check->status, ['cancelled', 'returned'], true)) {
            $this->deleteSourceLedger('incoming_check', (int) $check->id);

            return null;
        }

        if ($check->status === 'cashed_to_box') {
            return DebtTransaction::query()
                ->active()
                ->where('source', 'incoming_check')
                ->where('source_id', (int) $check->id)
                ->first();
        }

        if ($check->status === 'cashed_to_person') {
            $customerId = $check->to_customer ? (int) $check->to_customer : null;
            $sellerId = $check->to_seller ? (int) $check->to_seller : null;

            if (! $customerId && ! $sellerId) {
                return null;
            }

            $currency = $this->normalizeCurrency($check->currency);
            $amount = (float) $check->total;
            $note = $this->incomingCheckLedgerNote($check, 'بعد التصرف في الشيك');

            return $this->upsertSourceLedgerEntry(
                'incoming_check',
                $check->id,
                $customerId,
                $sellerId,
                'given',
                $amount,
                $note,
                now()->format('Y-m-d'),
                $currency
            );
        }

        return $this->syncIncomingCheckReceiveToLedger($check);
    }

    /**
     * شيك صادر بعد التصرف (أعطيت).
     */
    public function syncOutgoingCheckToLedger(OutgoingCheck $check): ?DebtTransaction
    {
        if (in_array($check->status, ['returned', 'cancelled'], true)) {
            $this->deleteSourceLedger('outgoing_check', (int) $check->id);

            return null;
        }

        $customerId = $check->customer_id ? (int) $check->customer_id : null;
        $sellerId = $check->seller_id ? (int) $check->seller_id : null;

        if (! $customerId && ! $sellerId) {
            $this->deleteSourceLedger('outgoing_check', (int) $check->id);

            return null;
        }

        $currency = $this->normalizeCurrency($check->currency);
        $amount = (float) $check->total;
        $note = $this->isOutgoingCheckDisposed($check->status)
            ? $this->outgoingCheckLedgerNote($check, 'بعد صرف الشيك')
            : $this->outgoingCheckLedgerNote($check, 'شيك صادر');

        return $this->upsertSourceLedgerEntry(
            'outgoing_check',
            $check->id,
            $customerId,
            $sellerId,
            'given',
            $amount,
            $note,
            $check->created_at?->format('Y-m-d') ?? now()->format('Y-m-d'),
            $currency
        );
    }

    public function deleteSourceLedger(string $source, int $sourceId, bool $logActivity = true): void
    {
        $transactions = DebtTransaction::query()
            ->active()
            ->where('source', $source)
            ->where('source_id', $sourceId)
            ->get();

        foreach ($transactions as $transaction) {
            if ($logActivity) {
                $this->activity()->logForTransaction(
                    $transaction,
                    'auto_removed',
                    'إزالة قيد دفتر ديون تلقائي',
                    'إزالة معاملة مرتبطة بـ '.$this->ledgerSourceActivitySuffix($source, $sourceId),
                    ['snapshot' => $this->activity()->transactionSnapshot($transaction)]
                );
            }
            $this->deleteTransaction($transaction, logActivity: false);
        }
    }

    private function upsertSourceLedgerEntry(
        string $source,
        int $sourceId,
        ?int $customerId,
        ?int $sellerId,
        string $type,
        float $amount,
        string $note,
        string $transactionDate,
        string $currency = 'شيكل'
    ): ?DebtTransaction {
        $amount = round(max(0, $amount), 2);

        $existing = DebtTransaction::query()
            ->where('source', $source)
            ->where('source_id', $sourceId)
            ->whereNull('archived_at')
            ->whereNull('deleted_at')
            ->first();

        if ($amount <= 0.0001) {
            if ($existing) {
                $this->activity()->logForTransaction(
                    $existing,
                    'auto_removed',
                    'إزالة قيد دفتر ديون تلقائي',
                    'إزالة معاملة مرتبطة بـ '.$this->ledgerSourceActivitySuffix($source, $sourceId).' (لا مبلغ دين)',
                    ['snapshot' => $this->activity()->transactionSnapshot($existing)]
                );
                $this->deleteTransaction($existing, logActivity: false);
            }

            return null;
        }

        $currency = $this->normalizeCurrency($currency);
        $payload = [
            'customer_id' => $customerId,
            'seller_id' => $sellerId,
            'type' => $type,
            'amount' => $amount,
            'currency' => $currency,
            'transaction_date' => $transactionDate,
            'note' => $note,
            'box_id' => null,
            'source' => $source,
            'source_id' => $sourceId,
        ];

        if ($existing) {
            $before = $this->activity()->transactionSnapshot($existing);
            $updated = $this->updateTransaction($existing, $payload, logActivity: false);
            $this->activity()->logForTransaction(
                $updated,
                'auto_updated',
                'تحديث قيد دفتر ديون تلقائي',
                'تحديث معاملة مرتبطة بـ '.$this->ledgerSourceActivitySuffix($source, $sourceId),
                ['before' => $before, 'after' => $this->activity()->transactionSnapshot($updated)]
            );

            return $updated;
        }

        $created = $this->createTransaction($payload, auth()->id(), applyBox: false, logActivity: false);
        $this->activity()->logForTransaction(
            $created,
            'auto_created',
            'إضافة قيد دفتر ديون تلقائي',
            'إضافة معاملة مرتبطة بـ '.$this->ledgerSourceActivitySuffix($source, $sourceId),
            ['snapshot' => $this->activity()->transactionSnapshot($created)]
        );

        return $created;
    }

    private function instantSaleCashPaid(InstantSale $sale): float
    {
        if (! $sale->payment_box_id) {
            return 0.0;
        }

        return max(0, (float) ($sale->payment_box_value ?? 0));
    }

    private function instantSaleDebtAmount(InstantSale $sale): float
    {
        $total = (float) $sale->total_cost;
        $cash = $this->instantSaleCashPaid($sale);

        return max(0, round($total - $cash, 2));
    }

    private function isOutgoingCheckDisposed(?string $status): bool
    {
        return in_array($status, ['cashed_to_person', 'cashed_from_box', 'cashed'], true);
    }

    /**
     * Move instant-sale ledger lines to "deleted" (e.g. cancelled invoice — not restorable).
     */
    public function deleteInstantSaleLedger(InstantSale $sale): void
    {
        $this->deleteSourceLedger('instant_sale', $sale->id);
    }

    /**
     * إنشاء زبون/مورد تلقائياً عند بيع فوري عليه دين ولم يُربَط بشخص في النظام.
     */
    public function ensureInstantSalePersonForLedger(InstantSale $sale): InstantSale
    {
        [$customerId, $sellerId] = $this->resolveInstantSalePersonIds($sale);
        if ($customerId || $sellerId) {
            return $sale;
        }

        if ($this->instantSaleDebtAmount($sale) <= 0) {
            return $sale;
        }

        $name = trim((string) ($sale->buyer_name ?? ''));
        if ($name === '' || $name === '-') {
            return $sale;
        }

        $phone = trim((string) ($sale->buyer_phone ?? ''));
        $address = trim((string) ($sale->buyer_address ?? ''));
        $isSeller = in_array($sale->buyer_type, ['seller', 'trader'], true);

        $payload = ['name' => $name];
        if ($phone !== '') {
            $payload['phone'] = $phone;
        }
        if ($address !== '') {
            $payload['address'] = $address;
        }
        $payload['type'] = $isSeller ? 'wholesale' : 'retail';

        if ($isSeller) {
            $seller = Seller::create($payload);
            $sale->update([
                'seller_id' => $seller->id,
                'buyer_type' => 'seller',
            ]);
        } else {
            $customer = Customer::create($payload);
            $sale->update([
                'buyer_id' => $customer->id,
                'buyer_type' => 'customer',
            ]);
        }

        return $sale->fresh();
    }

    /**
     * @return array{0: ?int, 1: ?int} [customer_id, seller_id]
     */
    private function resolveInstantSalePersonIds(InstantSale $sale): array
    {
        if ($sale->seller_id) {
            return [null, (int) $sale->seller_id];
        }

        if ($sale->buyer_id) {
            $customer = Customer::find($sale->buyer_id);
            if ($customer) {
                $type = strtolower(trim((string) ($customer->type ?? '')));
                $traderTypes = ['trader', 'تاجر', 'seller', 'مورد', 'supplier'];
                $isTrader = in_array($type, $traderTypes, true)
                    || in_array($sale->buyer_type, ['trader', 'seller'], true);

                if ($isTrader) {
                    $seller = Seller::query()
                        ->when($customer->phone, fn ($q) => $q->where('phone', $customer->phone))
                        ->where('name', $customer->name)
                        ->first();

                    if ($seller) {
                        return [null, (int) $seller->id];
                    }
                }

                return [(int) $sale->buyer_id, null];
            }
        }

        if (in_array($sale->buyer_type, ['trader', 'seller'], true)) {
            $seller = Seller::query()
                ->when($sale->buyer_phone, fn ($q) => $q->where('phone', $sale->buyer_phone))
                ->where('name', $sale->buyer_name)
                ->first();
            if ($seller) {
                return [null, (int) $seller->id];
            }
        }

        if ($sale->buyer_type === 'customer' && $sale->buyer_id) {
            return [(int) $sale->buyer_id, null];
        }

        $buyerName = trim((string) ($sale->buyer_name ?? ''));
        if ($buyerName !== '' && $buyerName !== '-') {
            $customer = Customer::query()
                ->when($sale->buyer_phone, fn ($q) => $q->where('phone', $sale->buyer_phone))
                ->where('name', $buyerName)
                ->first();

            if ($customer) {
                return [(int) $customer->id, null];
            }

            $seller = Seller::query()
                ->when($sale->buyer_phone, fn ($q) => $q->where('phone', $sale->buyer_phone))
                ->where('name', $buyerName)
                ->first();

            if ($seller) {
                return [null, (int) $seller->id];
            }
        }

        return [null, null];
    }

    public function applyBoxMovement(DebtTransaction $transaction, int $boxId): void
    {
        $box = Box::findOrFail($boxId);

        if ($this->normalizeCurrency($box->currency) !== $this->normalizeCurrency($transaction->currency)) {
            throw new \RuntimeException(__('messages.must_be_same_currency_check'));
        }

        $personName = $transaction->customer_id
            ? $transaction->customer?->name
            : $transaction->seller?->name;

        if ($transaction->type === 'taken') {
            $box->update(['total' => $box->total + $transaction->amount]);
            BoxLogs::createBoxLog(
                $box,
                'دفتر الديون - أخذت من ' . $personName,
                'add',
                $transaction->amount,
                $transaction->note
            );
        } else {
            $box->update(['total' => $box->total - $transaction->amount]);
            BoxLogs::createBoxLog(
                $box,
                'دفتر الديون - أعطيت لـ ' . $personName,
                'minus',
                $transaction->amount,
                $transaction->note
            );
        }
    }

    public function getPersonInfo(?int $customerId, ?int $sellerId): array
    {
        if ($customerId) {
            $person = Customer::findOrFail($customerId);

            return $this->formatPersonInfo($person, 'customer');
        }

        $person = Seller::findOrFail($sellerId);

        return $this->formatPersonInfo($person, 'seller');
    }

    /**
     * @param  Customer|Seller  $person
     */
    private function formatPersonInfo($person, string $personType): array
    {
        return [
            'id' => $person->id,
            'name' => $person->name,
            'phone' => $person->phone,
            'person_type' => $personType,
            'notes' => $person->notes,
            'collection_reminder_at' => $person->collection_reminder_at?->format('Y-m-d'),
        ];
    }

    public function updatePersonMeta(
        ?int $customerId,
        ?int $sellerId,
        ?string $notes = null,
        ?string $collectionReminderAt = null,
        bool $touchNotes = false,
        bool $touchReminder = false
    ): array {
        if ($customerId) {
            $person = Customer::findOrFail($customerId);
        } else {
            $person = Seller::findOrFail($sellerId);
        }

        $payload = [];

        if ($touchNotes) {
            $payload['notes'] = $notes;
        }

        if ($touchReminder) {
            $payload['collection_reminder_at'] = $collectionReminderAt;
        }

        if ($payload !== []) {
            $person->update($payload);
            $person = $person->fresh();

            $personName = $person->name ?? '';
            $parts = [];
            if ($touchNotes) {
                $parts[] = 'تحديث ملاحظات الدفتر: '.($notes ?? '—');
            }
            if ($touchReminder) {
                $parts[] = 'تحديث تذكير التحصيل: '.($collectionReminderAt ?? '—');
            }

            $this->activity()->log(
                'person_meta_updated',
                'تحديث بيانات شخص في دفتر الديون',
                implode(' — ', $parts).' — '.$personName,
                $customerId,
                $sellerId,
                null,
                ['notes' => $notes, 'collection_reminder_at' => $collectionReminderAt]
            );
        }

        $type = $customerId ? 'customer' : 'seller';

        return $this->formatPersonInfo($person, $type);
    }

    private function formatPersonImageUrl(object $person, bool $isCustomer): ?string
    {
        $images = $person->ID_image ?? null;
        if (! is_array($images) || count($images) === 0) {
            return null;
        }

        $first = $images[0] ?? null;
        if (! is_string($first) || trim($first) === '') {
            return null;
        }

        $folder = $isCustomer ? 'customer' : 'seller';

        return 'public/'.$folder.'Images/ID/'.$first;
    }

    /**
     * كل العملاء/الموردين النشطين لاختيار إضافة دين (بدون شرط وجود معاملات).
     */
    public function getPeoplePickerList(string $type, ?string $search = null): array
    {
        $isCustomers = $type === 'customers';
        $modelClass = $isCustomers ? Customer::class : Seller::class;

        $peopleQuery = $modelClass::query()->where('is_canceled', false);

        if ($search) {
            $peopleQuery->where(function ($q) use ($search) {
                $q->where('name', 'like', '%'.$search.'%')
                    ->orWhere('phone', 'like', '%'.$search.'%');
            });
        }

        return $peopleQuery->orderBy('name')->get()->map(function ($person) use ($isCustomers) {
            return [
                'id' => $person->id,
                'name' => $person->name,
                'phone' => $person->phone,
                'image_url' => $this->formatPersonImageUrl($person, $isCustomers),
                'person_type' => $isCustomers ? 'customer' : 'seller',
            ];
        })->values()->all();
    }

    public function getPeopleList(
        string $type,
        ?string $search = null,
        ?string $startDate = null,
        ?string $endDate = null,
        ?string $currency = null,
        ?int $categoryId = null
    ): array {
        $isCustomers = $type === 'customers';
        $modelClass = $isCustomers ? Customer::class : Seller::class;
        $foreignKey = $isCustomers ? 'customer_id' : 'seller_id';
        $filterCurrency = $currency ? $this->normalizeCurrency($currency) : null;

        $peopleQuery = $modelClass::query()->where('is_canceled', false);

        if ($categoryId) {
            $peopleQuery->whereIn('id', ContactCategoryAssignment::query()
                ->where('contact_category_id', $categoryId)
                ->whereNotNull($isCustomers ? 'customer_id' : 'seller_id')
                ->pluck($isCustomers ? 'customer_id' : 'seller_id'));
        }

        if ($search) {
            $peopleQuery->where(function ($q) use ($search) {
                $q->where('name', 'like', '%' . $search . '%')
                    ->orWhere('phone', 'like', '%' . $search . '%');
            });
        }

        $people = $peopleQuery->orderBy('name')->get();
        $result = [];

        foreach ($people as $person) {
            $txQuery = DebtTransaction::query()
                ->active()
                ->where($foreignKey, $person->id)
                ->whereNull($isCustomers ? 'seller_id' : 'customer_id');

            $this->applyDateFilter($txQuery, $startDate, $endDate);

            if ((clone $txQuery)->count() === 0) {
                continue;
            }

            $balancesByCurrency = [];
            foreach (self::CURRENCIES as $currencyCode) {
                $currencyQuery = (clone $txQuery)->where('currency', $currencyCode);
                $takenCur = (float) (clone $currencyQuery)->where('type', 'taken')->sum('amount');
                $givenCur = (float) (clone $currencyQuery)->where('type', 'given')->sum('amount');
                $balancesByCurrency[$currencyCode] = [
                    'total_taken' => $takenCur,
                    'total_given' => $givenCur,
                    'balance' => $takenCur - $givenCur,
                ];
            }

            $displayCurrency = $filterCurrency ?? 'شيكل';
            if ($filterCurrency && ! (clone $txQuery)->where('currency', $filterCurrency)->exists()) {
                continue;
            }

            $taken = $balancesByCurrency[$displayCurrency]['total_taken'];
            $given = $balancesByCurrency[$displayCurrency]['total_given'];
            $balance = $balancesByCurrency[$displayCurrency]['balance'];

            $lastTxQuery = $filterCurrency
                ? (clone $txQuery)->where('currency', $filterCurrency)
                : clone $txQuery;

            $lastTransaction = $lastTxQuery
                ->orderByDesc('transaction_date')
                ->orderByDesc('id')
                ->first();

            $transactionsCount = $filterCurrency
                ? (clone $txQuery)->where('currency', $filterCurrency)->count()
                : (clone $txQuery)->count();

            $result[] = [
                'id' => $person->id,
                'name' => $person->name,
                'phone' => $person->phone,
                'image_url' => $this->formatPersonImageUrl($person, $isCustomers),
                'person_type' => $isCustomers ? 'customer' : 'seller',
                'total_taken' => $taken,
                'total_given' => $given,
                'balance' => $balance,
                'balances' => $balancesByCurrency,
                'last_transaction' => $lastTransaction ? [
                    'id' => $lastTransaction->id,
                    'type' => $lastTransaction->type,
                    'type_label' => $lastTransaction->type === 'taken' ? 'أخذت' : 'أعطيت',
                    'amount' => (float) $lastTransaction->amount,
                    'currency' => $this->normalizeCurrency($lastTransaction->currency),
                    'transaction_date' => $lastTransaction->transaction_date?->format('Y-m-d'),
                    'created_at' => $lastTransaction->created_at?->format('Y-m-d H:i:s'),
                ] : null,
                'transactions_count' => $transactionsCount,
            ];
        }

        usort($result, function ($a, $b) {
            $aDate = $a['last_transaction']['created_at'] ?? '';
            $bDate = $b['last_transaction']['created_at'] ?? '';

            return strcmp($bDate, $aDate);
        });

        return $result;
    }

    public function recalculateBalances(?int $customerId, ?int $sellerId): void
    {
        $transactions = $this->baseQuery($customerId, $sellerId)
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();

        foreach (self::CURRENCIES as $currency) {
            $running = 0.0;

            foreach ($transactions->where('currency', $currency) as $transaction) {
                $amount = (float) $transaction->amount;
                $running = $transaction->type === 'taken'
                    ? $running + $amount
                    : $running - $amount;

                if ((float) $transaction->balance_after !== $running) {
                    $transaction->update(['balance_after' => $running]);
                }
            }
        }
    }

    public function archiveTransaction(DebtTransaction $transaction, bool $logActivity = true): void
    {
        if ($transaction->deleted_at) {
            throw new \RuntimeException(__('messages.ledger_transaction_not_restorable'));
        }

        DB::transaction(function () use ($transaction, $logActivity) {
            $before = $this->activity()->transactionSnapshot($transaction);

            if ($this->shouldSyncBox($transaction) && $transaction->box_id) {
                $this->reverseBoxMovement(
                    $transaction,
                    (int) $transaction->box_id,
                    $transaction->type,
                    (float) $transaction->amount
                );
            }

            $transaction->update(['archived_at' => now()]);
            $this->recalculateBalances($transaction->customer_id, $transaction->seller_id);

            if ($logActivity) {
                $this->activity()->logForTransaction(
                    $transaction->fresh(),
                    'transaction_archived',
                    'أرشفة معاملة دفتر ديون',
                    'أرشفة معاملة #'.$transaction->id,
                    ['before' => $before]
                );
            }
        });
    }

    public function deleteTransaction(DebtTransaction $transaction, bool $logActivity = true): void
    {
        DB::transaction(function () use ($transaction, $logActivity) {
            if ($transaction->archived_at || $transaction->deleted_at) {
                return;
            }

            $before = $this->activity()->transactionSnapshot($transaction);

            if ($this->shouldSyncBox($transaction) && $transaction->box_id) {
                $this->reverseBoxMovement(
                    $transaction,
                    (int) $transaction->box_id,
                    $transaction->type,
                    (float) $transaction->amount
                );
            }

            $transaction->update(['deleted_at' => now()]);
            $this->recalculateBalances($transaction->customer_id, $transaction->seller_id);

            if ($logActivity) {
                $this->activity()->logForTransaction(
                    $transaction,
                    'transaction_deleted',
                    'حذف نهائي لمعاملة دفتر ديون',
                    'حذف نهائي للمعاملة #'.$transaction->id,
                    ['before' => $before]
                );
            }
        });
    }

    public function updateTransaction(
        DebtTransaction $transaction,
        array $data,
        bool $logActivity = true
    ): DebtTransaction {
        return DB::transaction(function () use ($transaction, $data, $logActivity) {
            $before = $this->activity()->transactionSnapshot($transaction);
            if ($this->shouldSyncBox($transaction) && $transaction->box_id) {
                $this->reverseBoxMovement(
                    $transaction,
                    (int) $transaction->box_id,
                    $transaction->type,
                    (float) $transaction->amount
                );
            }

            $currency = array_key_exists('currency', $data)
                ? $this->normalizeCurrency($data['currency'])
                : $this->normalizeCurrency($transaction->currency);

            $updatePayload = [
                'type' => $data['type'],
                'amount' => $data['amount'],
                'currency' => $currency,
                'transaction_date' => $data['transaction_date'],
                'note' => $data['note'] ?? null,
            ];

            if (array_key_exists('box_id', $data)) {
                $updatePayload['box_id'] = $data['box_id'];
            }

            $transaction->update($updatePayload);

            if (array_key_exists('receipt_images', $data) && $data['receipt_images'] !== null) {
                $transaction->update(['receipt_images' => $data['receipt_images']]);
            }

            $this->recalculateBalances($transaction->customer_id, $transaction->seller_id);

            $transaction = $transaction->fresh(['customer', 'seller']);

            $newBoxId = $transaction->box_id;
            if ($this->shouldSyncBox($transaction) && $newBoxId) {
                $this->applyBoxMovement($transaction, (int) $newBoxId);
            }

            $transaction = $transaction->fresh(['customer', 'seller']);

            if ($logActivity) {
                $this->activity()->logForTransaction(
                    $transaction,
                    'transaction_updated',
                    'تعديل معاملة دفتر ديون',
                    'تعديل المعاملة #'.$transaction->id,
                    [
                        'before' => $before,
                        'after' => $this->activity()->transactionSnapshot($transaction),
                    ]
                );
            }

            return $transaction;
        });
    }

    private function shouldSyncBox(DebtTransaction $transaction): bool
    {
        $source = $transaction->source ?? 'manual';

        return $source === 'manual' || $source === '';
    }

    public function reverseBoxMovement(
        DebtTransaction $transaction,
        int $boxId,
        string $type,
        float $amount
    ): void {
        $box = Box::findOrFail($boxId);

        $txCurrency = $this->normalizeCurrency($transaction->currency);
        if ($this->normalizeCurrency($box->currency) !== $txCurrency) {
            throw new \RuntimeException(__('messages.must_be_same_currency_check'));
        }

        $personName = $transaction->customer_id
            ? $transaction->customer?->name
            : $transaction->seller?->name;

        if ($type === 'taken') {
            $box->update(['total' => $box->total - $amount]);
            BoxLogs::createBoxLog(
                $box,
                'دفتر الديون - تعديل (إلغاء أخذت) ' . $personName,
                'minus',
                $amount,
                $transaction->note
            );
        } else {
            $box->update(['total' => $box->total + $amount]);
            BoxLogs::createBoxLog(
                $box,
                'دفتر الديون - تعديل (إلغاء أعطيت) ' . $personName,
                'add',
                $amount,
                $transaction->note
            );
        }
    }
}
