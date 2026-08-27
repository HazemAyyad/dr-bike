<?php

namespace App\Services;

use App\Enums\PurchaseReturnStatus;
use App\Models\Bill;
use App\Models\BillItem;
use App\Models\DebtTransaction;
use App\Models\Product;
use App\Models\ProductStockMovement;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnSettlement;
use App\Models\ReturnModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseReturnService
{
    public function __construct(
        private InventoryCostingService $costing,
        private DebtLedgerService $ledger,
        private PurchaseActivityService $activity,
    ) {}

    public function availableItems(Bill $bill): array
    {
        return $bill->items()->with(['product', 'size', 'sizeColor'])->get()
            ->map(function (BillItem $item) {
                $returned = (float) $item->purchaseReturnItems()
                    ->whereHas('return', fn ($q) => $q->whereIn('status', [
                        PurchaseReturnStatus::Confirmed->value,
                        PurchaseReturnStatus::Delivered->value,
                        PurchaseReturnStatus::Settled->value,
                    ]))->sum('quantity');
                $received = (float) $item->received_owned_quantity;

                return [
                    'bill_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'product_name' => $item->product?->nameAr,
                    'size_id' => $item->size_id,
                    'size_label' => $item->size?->size,
                    'size_color_id' => $item->size_color_id,
                    'color_label' => $item->sizeColor?->colorAr,
                    'received_quantity' => $received,
                    'returned_quantity' => $returned,
                    'available_quantity' => max(0, $received - $returned),
                    'unit_price' => (float) ($item->final_unit_price ?? $item->price),
                ];
            })->filter(fn ($item) => $item['available_quantity'] > 0)->values()->all();
    }

    public function createDraft(array $data, ?int $userId): ReturnModel
    {
        return DB::transaction(function () use ($data, $userId) {
            $bill = ! empty($data['bill_id'])
                ? Bill::query()->lockForUpdate()->findOrFail($data['bill_id'])
                : null;
            $return = ReturnModel::create([
                'number' => 'TEMP-'.uniqid(),
                'bill_id' => $bill?->id,
                'source_type' => $bill ? 'invoice' : 'direct',
                'seller_id' => $bill?->seller_id ?? ($data['seller_id'] ?? null),
                'customer_id' => $bill?->customer_id ?? ($data['customer_id'] ?? null),
                'currency' => $this->ledger->normalizeCurrency($bill?->currency ?? $data['currency']),
                'status' => PurchaseReturnStatus::Draft->value,
                'reason' => $data['reason'] ?? null,
                'notes' => $data['notes'] ?? null,
                'note' => $data['notes'] ?? null,
                'created_by' => $userId,
            ]);
            $return->update(['number' => 'PRT-'.str_pad((string) $return->id, 7, '0', STR_PAD_LEFT)]);
            $this->replaceDraftItems($return, $data['items']);
            $this->activity->log($bill, 'purchase_return_draft_created', 'إنشاء مسودة مرتجع شراء', 'تم إنشاء '.$return->number, null, $return->fresh('items')->toArray(), null, 'purchase_return', $return->id, $userId);

            return $return->fresh($this->relations());
        });
    }

    public function updateDraft(ReturnModel $return, array $data, ?int $userId): ReturnModel
    {
        return DB::transaction(function () use ($return, $data, $userId) {
            $return = ReturnModel::query()->lockForUpdate()->findOrFail($return->id);
            $this->assertStatus($return, PurchaseReturnStatus::Draft);
            $before = $return->load('items')->toArray();
            $return->update(['reason' => $data['reason'] ?? null, 'notes' => $data['notes'] ?? null, 'note' => $data['notes'] ?? null]);
            $this->replaceDraftItems($return, $data['items']);
            $this->activity->log($return->bill, 'purchase_return_draft_updated', 'تعديل مسودة مرتجع شراء', 'تم تعديل '.$return->number, $before, $return->fresh('items')->toArray(), null, 'purchase_return', $return->id, $userId);

            return $return->fresh($this->relations());
        });
    }

    public function confirm(ReturnModel $return, ?int $userId): ReturnModel
    {
        return DB::transaction(function () use ($return, $userId) {
            $return = ReturnModel::query()->with('items')->lockForUpdate()->findOrFail($return->id);
            $this->assertStatus($return, PurchaseReturnStatus::Draft);
            if ($return->items->isEmpty()) {
                throw ValidationException::withMessages(['items' => ['يجب إضافة صنف واحد على الأقل.']]);
            }

            foreach ($return->items as $line) {
                if ($line->bill_item_id) {
                    $billItem = BillItem::query()->lockForUpdate()->findOrFail($line->bill_item_id);
                    $available = $this->availableQuantity($billItem, $return->id);
                    if ((float) $line->quantity > $available + 0.0001) {
                        throw ValidationException::withMessages(['items' => ['الكمية المتاحة للصنف #'.$line->product_id.' هي '.$available.'.']]);
                    }
                }
                $cost = $this->costing->consumeOwnedStock(
                    Product::query()->lockForUpdate()->findOrFail($line->product_id),
                    (float) $line->quantity,
                    ProductStockMovement::TYPE_PURCHASE_RETURN,
                    'purchase_return',
                    $return->id,
                    $line->size_color_id,
                    $line->size_id,
                    $userId,
                    'اعتماد مرتجع شراء '.$return->number,
                );
                $line->update(['cost_total' => $cost['total_cost']]);
            }

            $return->update(['status' => PurchaseReturnStatus::Confirmed->value, 'confirmed_by' => $userId, 'confirmed_at' => now()]);
            $this->activity->log($return->bill, 'purchase_return_confirmed', 'اعتماد مرتجع شراء', 'تم إخراج أصناف '.$return->number.' من المخزون', null, $return->fresh('items')->toArray(), null, 'purchase_return', $return->id, $userId);

            return $return->fresh($this->relations());
        });
    }

    public function deliver(ReturnModel $return, ?int $userId): ReturnModel
    {
        return DB::transaction(function () use ($return, $userId) {
            $return = ReturnModel::query()->lockForUpdate()->findOrFail($return->id);
            if (! in_array($return->status, [PurchaseReturnStatus::Confirmed->value, 'pending'], true)) {
                throw ValidationException::withMessages(['status' => ['حالة المرتجع لا تسمح بتسجيل التسليم.']]);
            }
            $transaction = $this->ledger->createTransaction([
                'seller_id' => $return->seller_id,
                'customer_id' => $return->customer_id,
                'type' => 'given',
                'amount' => (float) $return->total,
                'currency' => $return->currency,
                'transaction_date' => now()->toDateString(),
                'source' => 'purchase_return',
                'source_id' => $return->id,
                'note' => 'رصيد لصالحنا من مرتجع شراء '.$return->number,
            ], $userId, false);
            $return->update([
                'status' => PurchaseReturnStatus::Delivered->value,
                'debt_transaction_id' => $transaction->id,
                'delivered_by' => $userId,
                'delivered_at' => now(),
            ]);
            $this->activity->log($return->bill, 'purchase_return_delivered', 'تسليم مرتجع شراء', 'تم تسليم '.$return->number.' وإنشاء رصيد في دفتر الديون', null, $return->fresh()->toArray(), null, 'purchase_return', $return->id, $userId);

            return $return->fresh($this->relations());
        });
    }

    public function settle(ReturnModel $return, array $data, ?int $userId): ReturnModel
    {
        return DB::transaction(function () use ($return, $data, $userId) {
            $return = ReturnModel::query()->lockForUpdate()->findOrFail($return->id);
            $this->assertStatus($return, PurchaseReturnStatus::Delivered);
            $remaining = round((float) $return->total - (float) $return->settled_amount, 4);
            $amount = round((float) $data['amount'], 4);
            if ($amount <= 0 || $amount > $remaining + 0.0001) {
                throw ValidationException::withMessages(['amount' => ['المبلغ أكبر من رصيد المرتجع المتاح وهو '.$remaining.'.']]);
            }

            $type = $data['type'];
            if ($type === 'debt_credit' && abs($amount - $remaining) > 0.0001) {
                throw ValidationException::withMessages(['amount' => ['ترك المرتجع دينًا يتطلب إغلاق كامل الرصيد المتبقي وهو '.$remaining.'.']]);
            }
            $boxId = $type === 'cash_refund' ? (int) $data['box_id'] : null;
            $billId = null;
            if ($type === 'bill_allocation') {
                $bill = Bill::query()->lockForUpdate()->findOrFail($data['bill_id']);
                if ((int) $bill->seller_id !== (int) $return->seller_id || (int) $bill->customer_id !== (int) $return->customer_id) {
                    throw ValidationException::withMessages(['bill_id' => ['الفاتورة لا تخص نفس مورد المرتجع.']]);
                }
                if ($this->ledger->normalizeCurrency($bill->currency) !== $return->currency) {
                    throw ValidationException::withMessages(['bill_id' => ['عملة الفاتورة لا تطابق عملة المرتجع.']]);
                }
                $billRemaining = max(0, (float) $bill->final_total - (float) $bill->paid_amount);
                if ($amount > $billRemaining + 0.0001) {
                    throw ValidationException::withMessages(['amount' => ['المبلغ أكبر من المتبقي على الفاتورة وهو '.$billRemaining.'.']]);
                }
                $paid = round((float) $bill->paid_amount + $amount, 4);
                $bill->update(['paid_amount' => $paid, 'payment_status' => $paid + 0.0001 >= (float) $bill->final_total ? 'paid' : 'partial']);
                $billId = $bill->id;
            }

            $transaction = $type === 'debt_credit' ? null : $this->ledger->createTransaction([
                'seller_id' => $return->seller_id,
                'customer_id' => $return->customer_id,
                'type' => 'taken',
                'amount' => $amount,
                'currency' => $return->currency,
                'transaction_date' => now()->toDateString(),
                'box_id' => $boxId,
                'source' => $type === 'cash_refund' ? 'purchase_refund' : 'purchase_settlement',
                'source_id' => $return->id,
                'note' => ($type === 'cash_refund' ? 'استرداد نقدي' : 'تخصيص على فاتورة #'.$billId).' من '.$return->number,
            ], $userId, $type === 'cash_refund');

            PurchaseReturnSettlement::create([
                'return_id' => $return->id,
                'type' => $type,
                'amount' => $amount,
                'currency' => $return->currency,
                'bill_id' => $billId,
                'box_id' => $boxId,
                'debt_transaction_id' => $transaction?->id,
                'notes' => $data['notes'] ?? null,
                'created_by' => $userId,
            ]);
            $settled = round((float) $return->settled_amount + $amount, 4);
            $isComplete = $settled + 0.0001 >= (float) $return->total;
            $return->update([
                'settled_amount' => $settled,
                'status' => $isComplete ? PurchaseReturnStatus::Settled->value : PurchaseReturnStatus::Delivered->value,
                'settled_by' => $isComplete ? $userId : null,
                'settled_at' => $isComplete ? now() : null,
            ]);
            $description = $type === 'debt_credit'
                ? 'تم إغلاق '.$return->number.' وترك '.$amount.' '.$return->currency.' رصيدًا على المورد في دفتر الديون'
                : 'تمت تسوية '.$amount.' '.$return->currency.' من '.$return->number;
            $this->activity->log($return->bill, 'purchase_return_settlement', 'تسوية مرتجع شراء', $description, null, $return->fresh('settlements')->toArray(), null, 'purchase_return', $return->id, $userId);

            return $return->fresh($this->relations());
        });
    }

    public function cancel(ReturnModel $return, string $reason, ?int $userId): ReturnModel
    {
        return DB::transaction(function () use ($return, $reason, $userId) {
            $return = ReturnModel::query()->with(['items', 'settlements'])->lockForUpdate()->findOrFail($return->id);
            if ($return->status === PurchaseReturnStatus::Cancelled->value) {
                return $return->fresh($this->relations());
            }

            foreach ($return->settlements as $settlement) {
                if ($settlement->type === 'bill_allocation' && $settlement->bill_id) {
                    $bill = Bill::query()->lockForUpdate()->find($settlement->bill_id);
                    if ($bill) {
                        $paid = max(0, round((float) $bill->paid_amount - (float) $settlement->amount, 4));
                        $bill->update([
                            'paid_amount' => $paid,
                            'payment_status' => $paid <= 0.0001 ? 'unpaid' : ($paid + 0.0001 >= (float) $bill->final_total ? 'paid' : 'partial'),
                        ]);
                    }
                }
                if ($settlement->debt_transaction_id) {
                    $tx = DebtTransaction::query()->lockForUpdate()->find($settlement->debt_transaction_id);
                    if ($tx) $this->ledger->deleteTransaction($tx);
                }
            }
            if ($return->debt_transaction_id) {
                $tx = DebtTransaction::query()->lockForUpdate()->find($return->debt_transaction_id);
                if ($tx) $this->ledger->deleteTransaction($tx);
            }

            if ($return->status !== PurchaseReturnStatus::Draft->value) {
                foreach ($return->items as $line) {
                    $unitCost = (float) $line->quantity > 0 ? (float) $line->cost_total / (float) $line->quantity : 0;
                    $this->costing->addOwnedStock(
                        Product::query()->lockForUpdate()->findOrFail($line->product_id),
                        (float) $line->quantity,
                        $unitCost,
                        $return->currency,
                        'purchase_return_cancel',
                        $return->id,
                        $line->size_color_id,
                        $line->size_id,
                        $userId,
                        'إلغاء مرتجع شراء '.$return->number,
                        ProductStockMovement::TYPE_PURCHASE_RETURN_CANCEL,
                    );
                }
            }
            $return->update([
                'status' => PurchaseReturnStatus::Cancelled->value,
                'cancelled_by' => $userId,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
                'settled_amount' => 0,
            ]);
            $this->activity->log($return->bill, 'purchase_return_cancelled', 'إلغاء مرتجع شراء', 'تم إلغاء '.$return->number, null, $return->fresh()->toArray(), null, 'purchase_return', $return->id, $userId);
            return $return->fresh($this->relations());
        });
    }

    private function replaceDraftItems(ReturnModel $return, array $rows): void
    {
        $return->items()->delete();
        $total = 0.0;
        $seen = [];
        foreach ($rows as $row) {
            $billItem = $return->bill_id
                ? BillItem::query()->where('bill_id', $return->bill_id)->findOrFail($row['bill_item_id'])
                : null;
            $productId = $billItem?->product_id ?? $row['product_id'];
            $sizeId = $billItem?->size_id ?? ($row['size_id'] ?? null);
            $sizeColorId = $billItem?->size_color_id ?? ($row['size_color_id'] ?? null);
            $key = $billItem?->id ?? implode(':', [$productId, $sizeId, $sizeColorId]);
            if (isset($seen[$key])) {
                throw ValidationException::withMessages(['items' => ['لا يمكن تكرار الصنف نفسه.']]);
            }
            $seen[$key] = true;
            $quantity = (float) $row['quantity'];
            if ($billItem) {
                $available = $this->availableQuantity($billItem, $return->id);
                if ($quantity <= 0 || $quantity > $available + 0.0001) {
                    throw ValidationException::withMessages(['items' => ['كمية الصنف #'.$billItem->product_id.' غير صالحة؛ المتاح '.$available.'.']]);
                }
            } else {
                Product::query()->findOrFail($productId);
            }
            $unitPrice = $billItem
                ? (float) ($billItem->final_unit_price ?? $billItem->price)
                : (float) $row['unit_price'];
            $lineTotal = round($quantity * $unitPrice, 4);
            PurchaseReturn::create([
                'return_id' => $return->id,
                'bill_id' => $return->bill_id,
                'bill_item_id' => $billItem?->id,
                'product_id' => $productId,
                'size_id' => $sizeId,
                'size_color_id' => $sizeColorId,
                'quantity' => $quantity,
                'price' => $unitPrice,
                'line_total' => $lineTotal,
                'reason' => $row['reason'] ?? null,
                'note' => $row['notes'] ?? null,
                'notes' => $row['notes'] ?? null,
            ]);
            $total += $lineTotal;
        }
        $return->update(['total' => round($total, 4)]);
    }

    private function availableQuantity(BillItem $item, ?int $excludingReturnId = null): float
    {
        $query = $item->purchaseReturnItems()->whereHas('return', fn ($q) => $q->whereIn('status', [
            PurchaseReturnStatus::Confirmed->value,
            PurchaseReturnStatus::Delivered->value,
            PurchaseReturnStatus::Settled->value,
        ]));
        if ($excludingReturnId) $query->where('return_id', '!=', $excludingReturnId);
        return max(0, (float) $item->received_owned_quantity - (float) $query->sum('quantity'));
    }

    private function assertStatus(ReturnModel $return, PurchaseReturnStatus $status): void
    {
        if ($return->status !== $status->value) {
            throw ValidationException::withMessages(['status' => ['حالة المرتجع لا تسمح بتنفيذ هذا الإجراء.']]);
        }
    }

    private function relations(): array
    {
        return ['bill', 'seller', 'customer', 'items.product', 'items.size', 'items.sizeColor', 'settlements'];
    }
}
