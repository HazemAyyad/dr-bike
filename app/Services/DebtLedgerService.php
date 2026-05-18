<?php

namespace App\Services;

use App\Http\Controllers\API\BoxLogs;
use App\Models\Box;
use App\Models\Customer;
use App\Models\DebtTransaction;
use App\Models\InstantSale;
use App\Models\Seller;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class DebtLedgerService
{
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

    public function calculateArchivedTotals(?int $customerId, ?int $sellerId): array
    {
        $query = $this->archivedQuery($customerId, $sellerId);

        $taken = (float) (clone $query)->where('type', 'taken')->sum('amount');
        $given = (float) (clone $query)->where('type', 'given')->sum('amount');

        return [
            'total_taken' => $taken,
            'total_given' => $given,
            'balance' => $taken - $given,
        ];
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
        DB::transaction(function () use ($transaction) {
            $transaction->update(['archived_at' => null]);
            $transaction = $transaction->fresh(['customer', 'seller']);

            if ($this->shouldSyncBox($transaction) && $transaction->box_id) {
                $this->applyBoxMovement($transaction, (int) $transaction->box_id);
            }

            $this->recalculateBalances($transaction->customer_id, $transaction->seller_id);
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

    public function getPreviousBalance(?int $customerId, ?int $sellerId): float
    {
        $latest = $this->baseQuery($customerId, $sellerId)
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->value('balance_after');

        return (float) ($latest ?? 0);
    }

    public function calculateTotals(?int $customerId, ?int $sellerId, ?string $startDate = null, ?string $endDate = null): array
    {
        $query = $this->baseQuery($customerId, $sellerId);
        $this->applyDateFilter($query, $startDate, $endDate);

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
        $customerQuery = DebtTransaction::query()->active()->whereNotNull('customer_id')->whereNull('seller_id');
        $sellerQuery = DebtTransaction::query()->active()->whereNotNull('seller_id')->whereNull('customer_id');

        $customerTaken = (float) (clone $customerQuery)->where('type', 'taken')->sum('amount');
        $customerGiven = (float) (clone $customerQuery)->where('type', 'given')->sum('amount');
        $sellerTaken = (float) (clone $sellerQuery)->where('type', 'taken')->sum('amount');
        $sellerGiven = (float) (clone $sellerQuery)->where('type', 'given')->sum('amount');

        return [
            'total_taken_customers' => $customerTaken,
            'total_given_customers' => $customerGiven,
            'balance_customers' => $customerTaken - $customerGiven,
            'total_taken_sellers' => $sellerTaken,
            'total_given_sellers' => $sellerGiven,
            'balance_sellers' => $sellerTaken - $sellerGiven,
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
        return [
            'id' => $transaction->id,
            'type' => $transaction->type,
            'type_label' => $transaction->type === 'taken' ? 'أخذت' : 'أعطيت',
            'amount' => (float) $transaction->amount,
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
        ];
    }

    public function createTransaction(array $data, ?int $userId = null, bool $applyBox = true): DebtTransaction
    {
        return DB::transaction(function () use ($data, $userId, $applyBox) {
            $previousBalance = $this->getPreviousBalance($data['customer_id'] ?? null, $data['seller_id'] ?? null);
            $amount = (float) $data['amount'];

            $balanceAfter = $data['type'] === 'taken'
                ? $previousBalance + $amount
                : $previousBalance - $amount;

            $transaction = DebtTransaction::create([
                'customer_id' => $data['customer_id'] ?? null,
                'seller_id' => $data['seller_id'] ?? null,
                'type' => $data['type'],
                'amount' => $amount,
                'balance_after' => $balanceAfter,
                'note' => $data['note'] ?? null,
                'receipt_images' => $data['receipt_images'] ?? null,
                'transaction_date' => $data['transaction_date'],
                'box_id' => $data['box_id'] ?? null,
                'source' => $data['source'] ?? 'manual',
                'source_id' => $data['source_id'] ?? null,
                'created_by' => $userId,
            ]);

            if ($applyBox && !empty($data['box_id'])) {
                $this->applyBoxMovement($transaction, (int) $data['box_id']);
            }

            return $transaction->fresh(['customer', 'seller']);
        });
    }

    /**
     * Record instant sale on the person's ledger (بيع فوري = أخذت).
     */
    public function syncInstantSaleToLedger(InstantSale $sale): ?DebtTransaction
    {
        if ($sale->parent_id || $sale->isCancelled()) {
            return null;
        }

        if (DebtTransaction::query()
            ->where('source', 'instant_sale')
            ->where('source_id', $sale->id)
            ->whereNull('archived_at')
            ->exists()) {
            return null;
        }

        $amount = (float) $sale->total_cost;
        if ($amount <= 0) {
            return null;
        }

        [$customerId, $sellerId] = $this->resolveInstantSalePersonIds($sale);
        if (!$customerId && !$sellerId) {
            return null;
        }

        $productName = $sale->product?->nameAr ?? $sale->offerPackage?->name ?? 'منتج';
        $note = trim('بيع فوري #'.$sale->id.' — '.$productName.($sale->notes ? ' — '.$sale->notes : ''));

        return $this->createTransaction([
            'customer_id' => $customerId,
            'seller_id' => $sellerId,
            'type' => 'taken',
            'amount' => $amount,
            'transaction_date' => $sale->created_at?->format('Y-m-d') ?? now()->format('Y-m-d'),
            'note' => $note,
            'box_id' => null,
            'source' => 'instant_sale',
            'source_id' => $sale->id,
        ], auth()->id(), applyBox: false);
    }

    public function archiveInstantSaleLedger(InstantSale $sale): void
    {
        $transactions = DebtTransaction::query()
            ->active()
            ->where('source', 'instant_sale')
            ->where('source_id', $sale->id)
            ->get();

        foreach ($transactions as $transaction) {
            $this->archiveTransaction($transaction);
        }
    }

    /**
     * @return array{0: ?int, 1: ?int} [customer_id, seller_id]
     */
    private function resolveInstantSalePersonIds(InstantSale $sale): array
    {
        if ($sale->seller_id) {
            return [null, (int) $sale->seller_id];
        }

        if (in_array($sale->buyer_type, ['seller'], true) && $sale->buyer_id) {
            return [null, (int) $sale->buyer_id];
        }

        if ($sale->buyer_type === 'customer' && $sale->buyer_id) {
            return [(int) $sale->buyer_id, null];
        }

        if (in_array($sale->buyer_type, ['trader', 'seller'], true)) {
            $seller = Seller::query()
                ->when($sale->buyer_phone, fn ($q) => $q->where('phone', $sale->buyer_phone))
                ->where('name', $sale->buyer_name)
                ->first();
            if ($seller) {
                return [null, $seller->id];
            }
        }

        if ($sale->buyer_id) {
            $customer = Customer::find($sale->buyer_id);
            if ($customer) {
                $type = strtolower(trim((string) ($customer->type ?? '')));
                $traderTypes = ['trader', 'تاجر', 'seller', 'مورد', 'supplier'];
                if (in_array($type, $traderTypes, true)) {
                    $seller = Seller::query()
                        ->when($customer->phone, fn ($q) => $q->where('phone', $customer->phone))
                        ->where('name', $customer->name)
                        ->first();

                    return [null, $seller?->id];
                }

                return [(int) $sale->buyer_id, null];
            }
        }

        return [null, null];
    }

    public function applyBoxMovement(DebtTransaction $transaction, int $boxId): void
    {
        $box = Box::findOrFail($boxId);

        if ($box->currency !== 'شيكل') {
            throw new \RuntimeException(__('messages.currency_shekel'));
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
        }

        $type = $customerId ? 'customer' : 'seller';

        return $this->formatPersonInfo($person, $type);
    }

    public function getPeopleList(string $type, ?string $search = null, ?string $startDate = null, ?string $endDate = null): array
    {
        $isCustomers = $type === 'customers';
        $modelClass = $isCustomers ? Customer::class : Seller::class;
        $foreignKey = $isCustomers ? 'customer_id' : 'seller_id';

        $peopleQuery = $modelClass::query()->where('is_canceled', false);

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

            $transactionsCount = (clone $txQuery)->count();

            if ($transactionsCount === 0 && ($startDate || $endDate)) {
                continue;
            }

            $taken = (float) (clone $txQuery)->where('type', 'taken')->sum('amount');
            $given = (float) (clone $txQuery)->where('type', 'given')->sum('amount');
            $lastTransaction = (clone $txQuery)
                ->orderByDesc('transaction_date')
                ->orderByDesc('id')
                ->first();

            $result[] = [
                'id' => $person->id,
                'name' => $person->name,
                'phone' => $person->phone,
                'total_taken' => $taken,
                'total_given' => $given,
                'balance' => $taken - $given,
                'last_transaction' => $lastTransaction ? [
                    'id' => $lastTransaction->id,
                    'type' => $lastTransaction->type,
                    'type_label' => $lastTransaction->type === 'taken' ? 'أخذت' : 'أعطيت',
                    'amount' => (float) $lastTransaction->amount,
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

        $running = 0.0;

        foreach ($transactions as $transaction) {
            $amount = (float) $transaction->amount;
            $running = $transaction->type === 'taken'
                ? $running + $amount
                : $running - $amount;

            if ((float) $transaction->balance_after !== $running) {
                $transaction->update(['balance_after' => $running]);
            }
        }
    }

    public function archiveTransaction(DebtTransaction $transaction): void
    {
        DB::transaction(function () use ($transaction) {
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
        });
    }

    public function updateTransaction(DebtTransaction $transaction, array $data): DebtTransaction
    {
        return DB::transaction(function () use ($transaction, $data) {
            if ($this->shouldSyncBox($transaction) && $transaction->box_id) {
                $this->reverseBoxMovement(
                    $transaction,
                    (int) $transaction->box_id,
                    $transaction->type,
                    (float) $transaction->amount
                );
            }

            $updatePayload = [
                'type' => $data['type'],
                'amount' => $data['amount'],
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

            return $transaction->fresh(['customer', 'seller']);
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

        if ($box->currency !== 'شيكل') {
            throw new \RuntimeException(__('messages.currency_shekel'));
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
