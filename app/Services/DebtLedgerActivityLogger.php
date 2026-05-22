<?php

namespace App\Services;

use App\Http\Controllers\API\Logs;
use App\Models\Customer;
use App\Models\DebtLedgerActivityLog;
use App\Models\DebtTransaction;
use App\Models\Seller;

class DebtLedgerActivityLogger
{
    public function log(
        string $action,
        string $title,
        string $description,
        ?int $customerId = null,
        ?int $sellerId = null,
        ?int $debtTransactionId = null,
        ?array $meta = null
    ): DebtLedgerActivityLog {
        $entry = DebtLedgerActivityLog::create([
            'debt_transaction_id' => $debtTransactionId,
            'customer_id' => $customerId,
            'seller_id' => $sellerId,
            'action' => $action,
            'title' => $title,
            'description' => $this->appendActorToDescription($description),
            'meta' => $meta,
            'created_by' => auth()->id(),
        ]);

        Logs::createLog($title, $entry->description, 'debt_ledger');

        return $entry;
    }

    public function logForTransaction(
        DebtTransaction $transaction,
        string $action,
        string $title,
        string $description,
        ?array $meta = null
    ): DebtLedgerActivityLog {
        return $this->log(
            $action,
            $title,
            $description,
            $transaction->customer_id,
            $transaction->seller_id,
            $transaction->id,
            $meta
        );
    }

    public function transactionSnapshot(DebtTransaction $transaction): array
    {
        return [
            'id' => $transaction->id,
            'type' => $transaction->type,
            'amount' => (float) $transaction->amount,
            'currency' => $transaction->currency ?? 'شيكل',
            'balance_after' => (float) $transaction->balance_after,
            'transaction_date' => $transaction->transaction_date?->format('Y-m-d'),
            'note' => $transaction->note,
            'source' => $transaction->source,
            'source_id' => $transaction->source_id,
        ];
    }

    public function personLabel(?int $customerId, ?int $sellerId): string
    {
        if ($customerId) {
            return Customer::find($customerId)?->name ?? 'زبون #'.$customerId;
        }
        if ($sellerId) {
            return Seller::find($sellerId)?->name ?? 'مورد #'.$sellerId;
        }

        return 'غير محدد';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getTransactionActivity(int $transactionId, int $limit = 50): array
    {
        return $this->formatEntries(
            DebtLedgerActivityLog::query()
                ->with('creator:id,name')
                ->where('debt_transaction_id', $transactionId)
                ->orderByDesc('id')
                ->limit($limit)
                ->get()
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getPersonActivity(?int $customerId, ?int $sellerId, int $limit = 80): array
    {
        $query = DebtLedgerActivityLog::query()->with('creator:id,name');

        if ($customerId) {
            $query->where('customer_id', $customerId)->whereNull('seller_id');
        } elseif ($sellerId) {
            $query->where('seller_id', $sellerId)->whereNull('customer_id');
        } else {
            return [];
        }

        return $this->formatEntries(
            $query->orderByDesc('id')->limit($limit)->get()
        );
    }

    /**
     * @param  \Illuminate\Support\Collection<int, DebtLedgerActivityLog>  $entries
     * @return array<int, array<string, mixed>>
     */
    private function formatEntries($entries): array
    {
        return $entries->map(fn (DebtLedgerActivityLog $log) => [
            'id' => $log->id,
            'action' => $log->action,
            'title' => $log->title,
            'description' => $log->description,
            'meta' => $log->meta,
            'debt_transaction_id' => $log->debt_transaction_id,
            'created_by' => $log->created_by,
            'created_by_name' => $log->creator?->name,
            'created_at' => $log->created_at?->format('Y-m-d H:i:s'),
        ])->values()->all();
    }

    private function appendActorToDescription(string $description): string
    {
        $user = auth()->user();
        if (! $user) {
            return $description;
        }

        $name = trim((string) ($user->name ?? ''));
        if ($name === '') {
            return $description;
        }

        return rtrim($description).' — بواسطة: '.$name;
    }
}
