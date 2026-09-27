<?php

namespace App\Services;

use App\Models\Bill;
use App\Models\Box;
use App\Models\DebtTransaction;
use App\Models\InventoryCostLayer;
use App\Models\Product;
use App\Models\ProductStockMovement;
use App\Models\PurchaseAttachment;
use App\Models\PurchaseInvoicePurgeBackup;
use App\Models\PurchasePriceHistory;
use App\Models\PurchaseProduct;
use App\Models\ReturnModel;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PurchaseInvoicePurgeService
{
    private const ACTIVE_RETURN_STATUSES = ['pending', 'confirmed', 'delivered', 'settled'];

    public function __construct(
        private readonly InventoryCostingService $costing,
        private readonly DebtLedgerService $ledger,
        private readonly AccountingService $accounting,
    ) {}

    /** @return array<string, mixed> */
    public function purge(Bill $bill, User $actor, string $reason): array
    {
        $filesToDelete = [];

        $result = DB::transaction(function () use ($bill, $actor, $reason, &$filesToDelete) {
            $bill = Bill::query()
                ->with([
                    'items',
                    'receipts.items',
                    'payments.allocations',
                    'purchaseReturns.items',
                    'purchaseReturns.settlements',
                ])
                ->lockForUpdate()
                ->findOrFail($bill->id);

            $attachments = $this->attachmentsFor($bill);
            $filesToDelete = $this->attachmentFiles($attachments);
            $backup = $this->createBackup($bill, $actor, $reason, $attachments);

            $this->reverseAccountingEntries($bill);
            $cashSummary = $this->reverseFinancialEffects($bill, (int) $actor->id);
            $stockSummary = $this->reverseStockEffects($bill, $backup, (int) $actor->id);

            $sellerId = $bill->seller_id ? (int) $bill->seller_id : null;
            $productIds = $bill->items->pluck('product_id')->filter()->map(fn ($id) => (int) $id)->unique();

            PurchasePriceHistory::query()->where('bill_id', $bill->id)->delete();
            PurchaseAttachment::query()->whereIn('id', $attachments->pluck('id'))->delete();

            foreach ($bill->purchaseReturns as $return) {
                $return->delete();
            }
            foreach ($bill->payments as $payment) {
                $payment->delete();
            }
            foreach ($bill->receipts as $receipt) {
                $receipt->delete();
            }

            $billId = (int) $bill->id;
            $bill->delete();

            foreach ($productIds as $productId) {
                $this->refreshLatestPriceCache($sellerId, $productId);
            }

            $summary = [
                'bill_id' => $billId,
                'backup_id' => (int) $backup->id,
                'backup_reference' => (string) $backup->reference,
                'stock' => $stockSummary,
                'cash' => $cashSummary,
                'returns_deleted' => $bill->purchaseReturns->count(),
                'payments_deleted' => $bill->payments->count(),
            ];
            $backup->update(['result_summary' => $summary]);

            return $summary;
        }, 3);

        foreach ($filesToDelete as $file) {
            try {
                Storage::disk($file['disk'])->delete($file['path']);
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        return $result;
    }

    private function reverseAccountingEntries(Bill $bill): void
    {
        foreach ($bill->receipts as $receipt) {
            $this->accounting->reverse('purchase_receipt', (int) $receipt->id, now(), 'حذف فاتورة شراء PUR-'.$bill->id, auth()->id());
        }
        foreach ($bill->payments as $payment) {
            $this->accounting->reverse('purchase_payment', (int) $payment->id, now(), 'حذف فاتورة شراء PUR-'.$bill->id, auth()->id());
        }
        foreach ($bill->purchaseReturns as $return) {
            $this->accounting->reverse('purchase_return', (int) $return->id, now(), 'حذف فاتورة شراء PUR-'.$bill->id, auth()->id());
        }
    }

    /** @return array<string, mixed> */
    private function reverseFinancialEffects(Bill $bill, int $actorId): array
    {
        $reversedCash = [];
        $deletedTransactions = [];

        foreach ($bill->purchaseReturns as $return) {
            foreach ($return->settlements as $settlement) {
                if ($settlement->type === 'bill_allocation' && $settlement->bill_id
                    && (int) $settlement->bill_id !== (int) $bill->id) {
                    $targetBill = Bill::query()->lockForUpdate()->find($settlement->bill_id);
                    if ($targetBill) {
                        $paid = max(0, round((float) $targetBill->paid_amount - (float) $settlement->amount, 4));
                        $targetBill->update([
                            'paid_amount' => $paid,
                            'payment_status' => $paid <= 0.0001
                                ? 'unpaid'
                                : ($paid + 0.0001 >= (float) $targetBill->final_total ? 'paid' : 'partially_paid'),
                        ]);
                    }
                }

                if ($settlement->debt_transaction_id) {
                    $this->reverseLinkedTransaction(
                        (int) $settlement->debt_transaction_id,
                        $reversedCash,
                        $deletedTransactions,
                    );
                }
            }

            if ($return->debt_transaction_id) {
                $this->reverseLinkedTransaction(
                    (int) $return->debt_transaction_id,
                    $reversedCash,
                    $deletedTransactions,
                );
            }
        }

        foreach ($bill->payments as $payment) {
            $transactionIds = collect([$payment->debt_transaction_id])
                ->filter()
                ->merge(
                    DebtTransaction::query()
                        ->active()
                        ->whereIn('source', ['purchase_initial_payment', 'purchase_payment'])
                        ->where('source_id', $payment->id)
                        ->pluck('id')
                )
                ->map(fn ($id) => (int) $id)
                ->unique();
            foreach ($transactionIds as $transactionId) {
                $this->reverseLinkedTransaction($transactionId, $reversedCash, $deletedTransactions);
            }
        }

        $invoiceTransactions = DebtTransaction::query()
            ->active()
            ->where('source', 'purchase_invoice')
            ->where('source_id', $bill->id)
            ->lockForUpdate()
            ->get();
        foreach ($invoiceTransactions as $transaction) {
            $this->ledger->deleteTransaction($transaction, true, true);
            $deletedTransactions[] = (int) $transaction->id;
        }

        return [
            'reversed_box_movements' => $reversedCash,
            'debt_transactions_deleted' => array_values(array_unique($deletedTransactions)),
            'performed_by' => $actorId,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $reversedCash
     * @param  array<int, int>  $deletedTransactions
     */
    private function reverseLinkedTransaction(int $transactionId, array &$reversedCash, array &$deletedTransactions): void
    {
        if (in_array($transactionId, $deletedTransactions, true)) {
            return;
        }

        $transaction = DebtTransaction::query()->active()->lockForUpdate()->find($transactionId);
        if (! $transaction) {
            return;
        }

        if ($transaction->box_id) {
            $box = Box::query()->lockForUpdate()->findOrFail($transaction->box_id);
            $before = (float) $box->total;
            $this->ledger->reverseBoxMovement(
                $transaction,
                (int) $transaction->box_id,
                (string) $transaction->type,
                (float) $transaction->amount,
                true,
            );
            $reversedCash[] = [
                'box_id' => (int) $box->id,
                'transaction_id' => (int) $transaction->id,
                'amount' => (float) $transaction->amount,
                'type' => (string) $transaction->type,
                'before' => $before,
                'after' => (float) $box->fresh()->total,
            ];
        }

        $this->accounting->reverse('debt_transaction', (int) $transaction->id, now(), 'حذف فاتورة الشراء المرتبطة', auth()->id());
        $this->ledger->deleteTransaction($transaction, true, true);
        $deletedTransactions[] = (int) $transaction->id;
    }

    /** @return array<string, mixed> */
    private function reverseStockEffects(Bill $bill, PurchaseInvoicePurgeBackup $backup, int $actorId): array
    {
        $purchaseLayerIds = $this->purchaseLayerIds($bill);
        $activeReturns = $bill->purchaseReturns->whereIn('status', self::ACTIVE_RETURN_STATUSES);
        $restoredReturnQuantity = 0.0;
        foreach ($activeReturns as $return) {
            foreach ($return->items as $returnItem) {
                $product = Product::withTrashed()->lockForUpdate()->findOrFail($returnItem->product_id);
                $restored = $this->costing->reverseOwnedStockConsumption(
                    product: $product,
                    quantity: (float) $returnItem->quantity,
                    originalReferenceType: 'purchase_return',
                    originalReferenceId: (int) $return->id,
                    movementType: ProductStockMovement::TYPE_PURCHASE_INVOICE_DELETE_RETURN_RESTORE,
                    reversalReferenceType: 'purchase_invoice_delete',
                    reversalReferenceId: (int) $backup->id,
                    sizeColorId: $returnItem->size_color_id ? (int) $returnItem->size_color_id : null,
                    sizeId: $returnItem->size_id ? (int) $returnItem->size_id : null,
                    userId: $actorId,
                    note: 'عكس مرتجع مرتبط قبل حذف فاتورة PUR-'.$bill->id,
                );
                $restoredReturnQuantity += (float) $restored['quantity'];
            }
        }

        $rows = [];
        $totalQuantity = 0.0;
        $pendingCostQuantity = 0.0;

        foreach ($bill->items as $item) {
            $quantity = max(0, round((float) $item->received_owned_quantity, 4));
            if ($quantity <= 0.0001) {
                continue;
            }

            $product = Product::withTrashed()->lockForUpdate()->findOrFail($item->product_id);
            $cost = $this->costing->consumeOwnedStock(
                product: $product,
                quantity: $quantity,
                movementType: ProductStockMovement::TYPE_PURCHASE_INVOICE_DELETE,
                referenceType: 'purchase_invoice_delete',
                referenceId: (int) $backup->id,
                sizeColorId: $item->size_color_id ? (int) $item->size_color_id : null,
                sizeId: $item->size_id ? (int) $item->size_id : null,
                userId: $actorId,
                note: 'عكس مخزون فاتورة شراء PUR-'.$bill->id,
                reason: 'حذف نهائي لفاتورة شراء: '.$backup->reason,
                allowNegative: true,
                preferredLayerIds: $purchaseLayerIds,
            );
            $totalQuantity += $quantity;
            $pendingCostQuantity += (float) ($cost['pending_quantity'] ?? 0);
            $rows[] = [
                'bill_item_id' => (int) $item->id,
                'product_id' => (int) $item->product_id,
                'size_id' => $item->size_id ? (int) $item->size_id : null,
                'size_color_id' => $item->size_color_id ? (int) $item->size_color_id : null,
                'quantity' => $quantity,
                'cost' => [
                    'method' => (string) ($cost['method'] ?? ''),
                    'total_cost' => (float) ($cost['total_cost'] ?? 0),
                    'unit_cost' => (float) ($cost['unit_cost'] ?? 0),
                    'cost_complete' => (bool) ($cost['cost_complete'] ?? true),
                    'pending_quantity' => (float) ($cost['pending_quantity'] ?? 0),
                ],
            ];
        }

        return [
            'return_quantity_restored_before_delete' => round($restoredReturnQuantity, 4),
            'quantity_removed' => round($totalQuantity, 4),
            'net_quantity_removed' => round($totalQuantity - $restoredReturnQuantity, 4),
            'pending_cost_quantity' => round($pendingCostQuantity, 4),
            'items' => $rows,
        ];
    }

    /** @return array<int, int> */
    private function purchaseLayerIds(Bill $bill): array
    {
        if (! Schema::hasTable('inventory_cost_layers')) {
            return [];
        }

        $receiptItemIds = $bill->receipts->flatMap(fn ($receipt) => $receipt->items)->pluck('id');
        $amanatIds = Schema::hasTable('purchase_amanat_stocks')
            ? DB::table('purchase_amanat_stocks')->where('bill_id', $bill->id)->pluck('id')
            : collect();
        $billItemIds = $bill->items->pluck('id');

        if ($receiptItemIds->isEmpty() && $amanatIds->isEmpty() && $billItemIds->isEmpty()) {
            return [];
        }

        $ids = InventoryCostLayer::query()
            ->where(function ($query) use ($receiptItemIds, $amanatIds, $billItemIds) {
                if ($receiptItemIds->isNotEmpty()) {
                    $query->orWhere(function ($source) use ($receiptItemIds) {
                        $source->where('source_type', 'purchase_receipt_item')
                            ->whereIn('source_id', $receiptItemIds);
                    });
                }
                if ($amanatIds->isNotEmpty()) {
                    $query->orWhere(function ($source) use ($amanatIds) {
                        $source->where('source_type', 'purchase_amanat_purchase')
                            ->whereIn('source_id', $amanatIds);
                    });
                }
                if ($billItemIds->isNotEmpty()) {
                    $query->orWhere(function ($source) use ($billItemIds) {
                        $source->where('source_type', 'purchase_issue_resolution')
                            ->whereIn('source_id', $billItemIds);
                    });
                }
            })
            ->lockForUpdate()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        // A sentinel keeps destructive reversal from consuming unrelated FIFO
        // layers when legacy data is missing its expected purchase layer.
        return $ids === [] ? [-1] : $ids;
    }

    private function createBackup(Bill $bill, User $actor, string $reason, Collection $attachments): PurchaseInvoicePurgeBackup
    {
        $receiptIds = $bill->receipts->pluck('id');
        $paymentIds = $bill->payments->pluck('id');
        $returnIds = $bill->purchaseReturns->pluck('id');
        $settlementIds = $bill->purchaseReturns->flatMap->settlements->pluck('id');
        $purchaseLayerIds = collect($this->purchaseLayerIds($bill))->filter(fn ($id) => $id > 0);
        $returnAllocationIds = Schema::hasTable('inventory_cost_allocations')
            ? DB::table('inventory_cost_allocations')
                ->where('reference_type', 'purchase_return')
                ->whereIn('reference_id', $returnIds)
                ->pluck('id')
            : collect();
        $debtIds = collect([$bill->purchaseReturns->pluck('debt_transaction_id'), $bill->payments->pluck('debt_transaction_id')])
            ->flatten()->filter()->map(fn ($id) => (int) $id);
        $debtIds = $debtIds->merge(
            $bill->purchaseReturns->flatMap->settlements->pluck('debt_transaction_id')->filter()
        )->merge(
            DebtTransaction::query()->where('source', 'purchase_invoice')->where('source_id', $bill->id)->pluck('id')
        )->unique()->values();

        $tables = [
            'bills' => $this->rows('bills', 'id', collect([$bill->id])),
            'bill_items' => $this->rows('bill_items', 'bill_id', collect([$bill->id])),
            'purchase_receipts' => $this->rows('purchase_receipts', 'bill_id', collect([$bill->id])),
            'purchase_receipt_items' => $this->rows('purchase_receipt_items', 'purchase_receipt_id', $receiptIds),
            'purchase_amanat_stocks' => $this->rows('purchase_amanat_stocks', 'bill_id', collect([$bill->id])),
            'purchase_issue_resolutions' => $this->rows('purchase_issue_resolutions', 'bill_id', collect([$bill->id])),
            'purchase_price_histories' => $this->rows('purchase_price_histories', 'bill_id', collect([$bill->id])),
            'purchase_payments' => $this->rows('purchase_payments', 'id', $paymentIds),
            'purchase_payment_allocations_by_payment' => $this->rows('purchase_payment_allocations', 'purchase_payment_id', $paymentIds),
            'purchase_payment_allocations_by_bill' => $this->rows('purchase_payment_allocations', 'bill_id', collect([$bill->id])),
            'returns' => $this->rows('returns', 'id', $returnIds),
            'purchase_returns' => $this->rows('purchase_returns', 'return_id', $returnIds),
            'purchase_return_settlements' => $this->rows('purchase_return_settlements', 'id', $settlementIds),
            'purchase_attachments' => $attachments->map(fn ($row) => (array) $row->getRawOriginal())->values()->all(),
            'purchase_activity_logs' => $this->rows('purchase_activity_logs', 'bill_id', collect([$bill->id])),
            'debt_transactions' => $this->rows('debt_transactions', 'id', $debtIds),
            'inventory_cost_layers' => $this->rows('inventory_cost_layers', 'id', $purchaseLayerIds),
            'inventory_cost_allocations_by_purchase_layer' => $this->rows('inventory_cost_allocations', 'inventory_cost_layer_id', $purchaseLayerIds),
            'inventory_cost_allocations_by_return' => $this->rows('inventory_cost_allocations', 'id', $returnAllocationIds),
        ];

        return PurchaseInvoicePurgeBackup::query()->create([
            'reference' => (string) Str::uuid(),
            'bill_id' => (int) $bill->id,
            'bill_reference' => 'PUR-'.$bill->id,
            'workflow_status' => $bill->workflow_status,
            'reason' => $reason,
            'payload' => [
                'version' => 1,
                'tables' => $tables,
                'files' => $this->attachmentFiles($attachments),
            ],
            'created_by' => $actor->id,
        ]);
    }

    private function attachmentsFor(Bill $bill): Collection
    {
        $returnIds = $bill->purchaseReturns->pluck('id');

        return PurchaseAttachment::query()
            ->where(function ($query) use ($bill, $returnIds) {
                $query->where('bill_id', $bill->id);
                if ($returnIds->isNotEmpty()) {
                    $query->orWhere(function ($returnAttachments) use ($returnIds) {
                        $returnAttachments->whereIn('attachable_type', ['purchase_return', ReturnModel::class])
                            ->whereIn('attachable_id', $returnIds);
                    });
                }
            })
            ->lockForUpdate()
            ->get();
    }

    /** @return array<int, array{disk:string,path:string}> */
    private function attachmentFiles(Collection $attachments): array
    {
        return $attachments->filter(fn ($row) => trim((string) $row->path) !== '')
            ->map(fn ($row) => [
                'disk' => trim((string) ($row->disk ?: 'public')),
                'path' => (string) $row->path,
            ])->values()->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function rows(string $table, string $column, Collection $ids): array
    {
        if ($ids->isEmpty() || ! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return [];
        }

        return DB::table($table)->whereIn($column, $ids)->orderBy('id')->get()
            ->map(fn ($row) => (array) $row)->values()->all();
    }

    private function refreshLatestPriceCache(?int $sellerId, int $productId): void
    {
        if (! $sellerId) {
            return;
        }

        $latest = PurchasePriceHistory::query()
            ->where('seller_id', $sellerId)
            ->where('product_id', $productId)
            ->latest('priced_at')
            ->latest('id')
            ->first();
        if ($latest) {
            PurchaseProduct::query()->updateOrCreate(
                ['seller_id' => $sellerId, 'product_id' => $productId],
                ['price' => $latest->unit_price],
            );
        } else {
            PurchaseProduct::query()->where('seller_id', $sellerId)->where('product_id', $productId)->delete();
        }
    }
}
