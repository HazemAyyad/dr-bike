<?php

namespace App\Services;

use App\Models\Bill;
use App\Models\BillItem;
use App\Models\Box;
use App\Models\Product;
use App\Models\ProductStockMovement;
use App\Models\PurchaseAmanatStock;
use App\Models\PurchaseIssueResolution;
use App\Models\PurchasePayment;
use App\Models\PurchasePaymentAllocation;
use App\Models\PurchaseReturn;
use App\Models\ReturnModel;
use Illuminate\Support\Facades\DB;

class PurchaseAccountService
{
    private const ISSUE_TYPES = ['damaged', 'mismatched'];
    private const ISSUE_RESOLUTIONS = ['return_to_supplier', 'replacement_expected', 'accept_with_discount', 'accept_negotiated_price', 'other_settlement'];

    public function __construct(
        private InventoryCostingService $costing,
        private DebtLedgerService $ledger,
        private ProductStockService $stockService,
        private PurchaseActivityService $activity,
    ) {
    }

    public function returnAmanat(PurchaseAmanatStock $amanat, float $quantity, ?string $note = null, ?int $userId = null): PurchaseAmanatStock
    {
        return DB::transaction(function () use ($amanat, $quantity, $note, $userId) {
            $amanat = PurchaseAmanatStock::query()->lockForUpdate()->findOrFail($amanat->id);
            if ($quantity <= 0 || $quantity > (float) $amanat->remaining_quantity + 0.0001) {
                throw new \RuntimeException(__('messages.entered_amount_bigger_than_quantity'));
            }

            $bill = Bill::query()->lockForUpdate()->findOrFail($amanat->bill_id);
            $item = BillItem::query()->lockForUpdate()->findOrFail($amanat->bill_item_id);
            $remaining = max(0, (float) $amanat->remaining_quantity - $quantity);

            $amanat->update([
                'remaining_quantity' => $remaining,
                'status' => $remaining <= 0.0001 ? 'returned' : 'partially_returned',
                'resolved_at' => $remaining <= 0.0001 ? now() : null,
                'notes' => $note ?? $amanat->notes,
            ]);

            $item->update([
                'custody_quantity' => max(0, (float) $item->custody_quantity - $quantity),
            ]);

            $this->activity->log($bill, 'amanat_returned', 'إرجاع أمانات للمورد', 'تم إرجاع كمية أمانات بدون إدخالها كمخزون مملوك', null, $amanat->fresh()->toArray(), null, 'purchase_amanat_stock', $amanat->id, $userId);

            return $amanat->fresh();
        });
    }

    public function paySupplierOnAccount(array $data, ?int $userId = null): PurchasePayment
    {
        return DB::transaction(function () use ($data, $userId) {
            $amount = (float) $data['amount'];
            if ($amount <= 0) {
                throw new \RuntimeException(__('messages.validation_failed'));
            }

            $box = Box::query()->lockForUpdate()->findOrFail($data['box_id']);
            $currency = $this->ledger->normalizeCurrency($data['currency'] ?? $box->currency);
            if ($this->ledger->normalizeCurrency($box->currency) !== $currency) {
                throw new \RuntimeException(__('messages.must_be_same_currency_check'));
            }

            $tx = $this->ledger->createTransaction([
                'customer_id' => $data['customer_id'] ?? null,
                'seller_id' => $data['seller_id'] ?? null,
                'type' => 'given',
                'amount' => $amount,
                'currency' => $currency,
                'transaction_date' => $data['paid_at'] ?? now()->toDateString(),
                'box_id' => $box->id,
                'source' => 'purchase_account_payment',
                'source_id' => null,
                'note' => $data['note'] ?? 'دفعة مورد على الحساب',
            ], $userId, true);

            $payment = PurchasePayment::create([
                'bill_id' => null,
                'seller_id' => $data['seller_id'] ?? null,
                'customer_id' => $data['customer_id'] ?? null,
                'box_id' => $box->id,
                'amount' => $amount,
                'currency' => $currency,
                'type' => 'account_payment',
                'paid_at' => $data['paid_at'] ?? now()->toDateString(),
                'note' => $data['note'] ?? null,
                'debt_transaction_id' => $tx->id,
                'created_by' => $userId,
            ]);

            $allocations = $data['allocations'] ?? [];
            if (! empty($allocations) && ! empty($data['allocate_oldest_first'])) {
                throw new \RuntimeException(__('messages.validation_failed'));
            }

            if (! empty($allocations)) {
                $this->allocatePaymentManually($payment, $allocations, $userId);
            } else {
                $this->allocatePayment($payment, (bool) ($data['allocate_oldest_first'] ?? false), $userId);
            }

            return $payment->fresh();
        });
    }

    public function createPurchaseReturn(array $data, ?int $userId = null): ReturnModel
    {
        return DB::transaction(function () use ($data, $userId) {
            $currency = $this->ledger->normalizeCurrency($data['currency'] ?? 'شيكل');
            $items = collect($data['products'] ?? []);
            $total = (float) ($data['total'] ?? $items->sum(fn ($item) => (float) $item['quantity'] * (float) $item['purchase_price']));

            $return = ReturnModel::create([
                'seller_id' => $data['seller_id'] ?? null,
                'customer_id' => $data['customer_id'] ?? null,
                'bill_id' => $data['bill_id'] ?? null,
                'total' => $total,
                'currency' => $currency,
                'status' => 'pending',
                'resolution' => $data['resolution'] ?? 'supplier_credit',
                'refund_box_id' => $data['refund_box_id'] ?? null,
                'note' => $data['note'] ?? null,
                'created_by' => $userId,
            ]);

            foreach ($items as $row) {
                $product = Product::query()->lockForUpdate()->findOrFail($row['product_id']);
                $quantity = (float) $row['quantity'];
                $unitPrice = (float) $row['purchase_price'];
                $billItem = ! empty($row['bill_item_id'])
                    ? BillItem::query()->find($row['bill_item_id'])
                    : null;
                $sizeColorId = $row['size_color_id'] ?? $billItem?->size_color_id;
                $sizeId = $row['size_id'] ?? $billItem?->size_id;

                $cost = $this->costing->consumeOwnedStock(
                    product: $product,
                    quantity: $quantity,
                    movementType: ProductStockMovement::TYPE_PURCHASE_RETURN,
                    referenceType: 'purchase_return',
                    referenceId: $return->id,
                    sizeColorId: $sizeColorId,
                    sizeId: $sizeId,
                    userId: $userId,
                    note: 'مرتجع شراء #'.$return->id,
                );

                PurchaseReturn::create([
                    'return_id' => $return->id,
                    'bill_id' => $data['bill_id'] ?? null,
                    'bill_item_id' => $billItem?->id,
                    'product_id' => $product->id,
                    'size_id' => $sizeId,
                    'size_color_id' => $sizeColorId,
                    'quantity' => $quantity,
                    'price' => $unitPrice,
                    'cost_total' => $cost['total_cost'],
                    'note' => $row['note'] ?? null,
                ]);
            }

            if (! empty($data['bill_id'])) {
                $bill = Bill::find($data['bill_id']);
                if ($bill) {
                    $this->activity->log($bill, 'purchase_return_created', 'إنشاء مرتجع شراء', 'تم إنشاء مرتجع شراء مرتبط بالفاتورة', null, $return->fresh('items')->toArray(), null, 'purchase_return', $return->id, $userId);
                }
            }

            return $return->fresh('items');
        });
    }

    public function deliverPurchaseReturn(ReturnModel $return, ?int $refundBoxId = null, ?int $userId = null): ReturnModel
    {
        return DB::transaction(function () use ($return, $refundBoxId, $userId) {
            $return = ReturnModel::query()->lockForUpdate()->findOrFail($return->id);
            if ($return->status === 'delivered') {
                return $return->fresh('items');
            }

            $resolution = $return->resolution ?: 'supplier_credit';
            $boxId = $refundBoxId ?: $return->refund_box_id;

            $tx = $this->ledger->createTransaction([
                'customer_id' => $return->customer_id,
                'seller_id' => $return->seller_id,
                'type' => $resolution === 'cash_refund' ? 'taken' : 'given',
                'amount' => (float) $return->total,
                'currency' => $return->currency ?? 'شيكل',
                'transaction_date' => now()->toDateString(),
                'box_id' => $resolution === 'cash_refund' ? $boxId : null,
                'source' => $resolution === 'cash_refund' ? 'purchase_refund' : 'purchase_return',
                'source_id' => $return->id,
                'note' => $resolution === 'cash_refund' ? 'استرداد نقدي من مورد لمرتجع شراء #'.$return->id : 'رصيد لصالحنا من مرتجع شراء #'.$return->id,
            ], $userId, $resolution === 'cash_refund');

            $return->update([
                'status' => 'delivered',
                'refund_box_id' => $boxId,
                'debt_transaction_id' => $tx->id,
                'delivered_at' => now(),
            ]);

            if ($return->bill_id) {
                $bill = Bill::find($return->bill_id);
                if ($bill) {
                    $this->activity->log($bill, $resolution === 'cash_refund' ? 'refund_received' : 'purchase_return_created', 'تسليم مرتجع شراء', 'تم تسليم المرتجع وتحديث الدفتر'.($resolution === 'cash_refund' ? ' والصندوق' : ''), null, $return->fresh()->toArray(), null, 'purchase_return', $return->id, $userId);
                }
            }

            return $return->fresh('items');
        });
    }

    public function resolvePurchaseIssue(array $data, ?int $userId = null): PurchaseIssueResolution
    {
        return DB::transaction(function () use ($data, $userId) {
            $issueType = $data['issue_type'];
            $resolution = $data['resolution'];
            if (! in_array($issueType, self::ISSUE_TYPES, true) || ! in_array($resolution, self::ISSUE_RESOLUTIONS, true)) {
                throw new \RuntimeException(__('messages.validation_failed'));
            }

            $bill = Bill::query()->lockForUpdate()->findOrFail($data['bill_id']);
            if ($bill->workflow_status === 'finalized') {
                throw new \RuntimeException(__('messages.something_wrong'));
            }

            $item = BillItem::query()
                ->where('bill_id', $bill->id)
                ->lockForUpdate()
                ->findOrFail($data['bill_item_id']);

            $quantity = (float) $data['quantity'];
            $available = $issueType === 'damaged'
                ? (float) $item->damaged_quantity
                : (float) $item->mismatched_quantity;
            if ($quantity <= 0 || $quantity > $available + 0.0001) {
                throw new \RuntimeException(__('messages.entered_amount_bigger_than_quantity'));
            }

            $unitPrice = array_key_exists('negotiated_unit_price', $data)
                ? (float) $data['negotiated_unit_price']
                : null;
            $financialAdjustment = (float) ($data['financial_adjustment'] ?? 0);

            if (in_array($resolution, ['accept_with_discount', 'accept_negotiated_price'], true)) {
                if ($unitPrice === null || $unitPrice < 0) {
                    throw new \RuntimeException(__('messages.validation_failed'));
                }

                $this->costing->addOwnedStock(
                    product: $item->product,
                    quantity: $quantity,
                    unitCost: $unitPrice,
                    currency: $bill->currency,
                    sourceType: 'purchase_issue_resolution',
                    sourceId: $item->id,
                    sizeColorId: $item->size_color_id,
                    sizeId: $item->size_id,
                    userId: $userId,
                    note: 'تسوية مشكلة استلام لفاتورة #'.$bill->id,
                );

                $item->update([
                    'received_owned_quantity' => (float) $item->received_owned_quantity + $quantity,
                    'final_unit_price' => $unitPrice,
                ]);
                $this->recordIssuePriceHistory($bill, $item, $unitPrice, $quantity, $userId);
            }

            $remainingIssueQuantity = max(0, $available - $quantity);
            $item->update([
                $issueType === 'damaged' ? 'damaged_quantity' : 'mismatched_quantity' => $remainingIssueQuantity,
                'status' => $remainingIssueQuantity <= 0.0001 ? 'reviewed' : $item->status,
            ]);

            $issue = PurchaseIssueResolution::create([
                'bill_id' => $bill->id,
                'bill_item_id' => $item->id,
                'purchase_receipt_item_id' => $data['purchase_receipt_item_id'] ?? null,
                'product_id' => $item->product_id,
                'issue_type' => $issueType,
                'resolution' => $resolution,
                'quantity' => $quantity,
                'negotiated_unit_price' => $unitPrice,
                'financial_adjustment' => $financialAdjustment,
                'reason' => $data['reason'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $userId,
            ]);

            $this->activity->log($bill, 'purchase_issue_resolved', 'تسوية مشكلة استلام', 'تم تسجيل قرار تسوية لمشكلة '.$issueType, null, $issue->toArray(), null, 'purchase_issue_resolution', $issue->id, $userId);

            return $issue->fresh();
        });
    }

    private function allocatePayment(PurchasePayment $payment, bool $oldestFirst, ?int $userId = null): void
    {
        if (! $oldestFirst) {
            return;
        }

        $remainingPayment = (float) $payment->amount;
        $bills = Bill::query()
            ->where('payment_status', '!=', 'paid')
            ->when($payment->seller_id, fn ($q) => $q->where('seller_id', $payment->seller_id))
            ->when($payment->customer_id, fn ($q) => $q->where('customer_id', $payment->customer_id))
            ->where('currency', $payment->currency)
            ->where('workflow_status', 'finalized')
            ->orderBy('finalized_at')
            ->lockForUpdate()
            ->get();

        foreach ($bills as $bill) {
            if ($remainingPayment <= 0.0001) {
                break;
            }

            $due = max(0, (float) $bill->final_total - (float) $bill->paid_amount);
            $apply = min($due, $remainingPayment);
            if ($apply <= 0) {
                continue;
            }

            $bill->update(['paid_amount' => (float) $bill->paid_amount + $apply]);
            $paid = (float) $bill->fresh()->paid_amount;
            $total = (float) $bill->final_total;
            $bill->update(['payment_status' => $paid + 0.0001 >= $total ? 'paid' : 'partially_paid']);
            PurchasePaymentAllocation::create([
                'purchase_payment_id' => $payment->id,
                'bill_id' => $bill->id,
                'amount' => $apply,
                'created_by' => $userId,
            ]);
            $remainingPayment -= $apply;
        }
    }

    private function allocatePaymentManually(PurchasePayment $payment, array $allocations, ?int $userId = null): void
    {
        $seen = [];
        $totalAllocated = 0.0;
        foreach ($allocations as $row) {
            $billId = (int) $row['bill_id'];
            if (isset($seen[$billId])) {
                throw new \RuntimeException(__('messages.validation_failed'));
            }
            $seen[$billId] = true;
            $amount = (float) $row['amount'];
            $totalAllocated += $amount;
            if ($totalAllocated > (float) $payment->amount + 0.0001) {
                throw new \RuntimeException(__('messages.entered_amount_bigger_than_quantity'));
            }

            $bill = Bill::query()
                ->where('id', $billId)
                ->where('workflow_status', 'finalized')
                ->where('currency', $payment->currency)
                ->when($payment->seller_id, fn ($q) => $q->where('seller_id', $payment->seller_id))
                ->when($payment->customer_id, fn ($q) => $q->where('customer_id', $payment->customer_id))
                ->lockForUpdate()
                ->firstOrFail();

            $due = max(0, (float) $bill->final_total - (float) $bill->paid_amount);
            if ($amount > $due + 0.0001) {
                throw new \RuntimeException(__('messages.entered_amount_bigger_than_quantity'));
            }

            $bill->update(['paid_amount' => (float) $bill->paid_amount + $amount]);
            $paid = (float) $bill->fresh()->paid_amount;
            $bill->update(['payment_status' => $paid + 0.0001 >= (float) $bill->final_total ? 'paid' : 'partially_paid']);
            PurchasePaymentAllocation::create([
                'purchase_payment_id' => $payment->id,
                'bill_id' => $bill->id,
                'amount' => $amount,
                'created_by' => $userId,
            ]);
        }
    }

    private function recordIssuePriceHistory(Bill $bill, BillItem $item, float $price, float $quantity, ?int $userId): void
    {
        \App\Models\PurchasePriceHistory::create([
            'product_id' => $item->product_id,
            'seller_id' => $bill->seller_id,
            'customer_id' => $bill->customer_id,
            'bill_id' => $bill->id,
            'bill_item_id' => $item->id,
            'unit_price' => $price,
            'quantity' => $quantity,
            'currency' => $bill->currency,
            'priced_at' => now()->toDateString(),
            'manual_override' => true,
            'created_by' => $userId,
        ]);
    }
}
