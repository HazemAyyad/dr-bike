<?php

namespace App\Services;

use App\Models\Bill;
use App\Models\BillItem;
use App\Models\Box;
use App\Models\DebtTransaction;
use App\Models\Product;
use App\Models\PurchaseAmanatStock;
use App\Models\PurchasePayment;
use App\Models\PurchasePriceHistory;
use App\Models\PurchaseProduct;
use App\Models\PurchaseReceipt;
use Illuminate\Support\Facades\DB;

class PurchasingService
{
    public function __construct(
        private InventoryCostingService $costing,
        private DebtLedgerService $ledger,
        private PurchaseActivityService $activity,
    ) {
    }

    public function createPurchase(array $data, ?int $userId = null): Bill
    {
        return DB::transaction(function () use ($data, $userId) {
            $currency = $this->normalizeCurrency($data['currency'] ?? 'شيكل');
            $items = collect($data['products'] ?? []);
            $total = (float) ($data['total'] ?? $items->sum(fn ($item) => (float) $item['quantity'] * (float) $item['purchase_price']));

            $bill = Bill::create([
                'seller_id' => $data['seller_id'] ?? null,
                'customer_id' => $data['customer_id'] ?? null,
                'total' => $total,
                'final_total' => 0,
                'paid_amount' => 0,
                'currency' => $currency,
                'status' => 'unfinished',
                'workflow_status' => 'awaiting_receiving',
                'payment_status' => 'unpaid',
                'notes' => $data['notes'] ?? null,
                'created_by' => $userId,
            ]);

            foreach ($items as $item) {
                $product = Product::query()->findOrFail($item['product_id']);
                $quantity = (float) $item['quantity'];
                $price = (float) $item['purchase_price'];

                $billItem = BillItem::create([
                    'bill_id' => $bill->id,
                    'product_id' => $product->id,
                    'size_id' => $item['size_id'] ?? null,
                    'size_color_id' => $item['size_color_id'] ?? null,
                    'quantity' => $quantity,
                    'ordered_quantity' => $quantity,
                    'received_owned_quantity' => 0,
                    'custody_quantity' => 0,
                    'damaged_quantity' => 0,
                    'mismatched_quantity' => 0,
                    'price' => $price,
                    'final_unit_price' => $price,
                    'status' => 'unfinished',
                ]);

                $this->recordPurchasePrice($bill, $billItem, $price, $quantity, $currency, (bool) ($item['manual_override'] ?? false), $userId);
                $this->updateLatestPriceCache($bill, $product->id, $price);
            }

            $this->activity->log($bill, 'purchase_created', 'إنشاء فاتورة شراء', 'تم إنشاء فاتورة شراء بانتظار الاستلام', null, $bill->toArray(), null, 'bill', $bill->id, $userId);

            return $bill->fresh(['items.product', 'seller', 'customer']);
        });
    }

    public function receive(Bill $bill, array $data, ?int $userId = null): PurchaseReceipt
    {
        return DB::transaction(function () use ($bill, $data, $userId) {
            $bill = Bill::query()->lockForUpdate()->findOrFail($bill->id);
            if (in_array($bill->workflow_status, ['finalized', 'cancelled'], true)) {
                throw new \RuntimeException(__('messages.something_wrong'));
            }

            $receipt = PurchaseReceipt::create([
                'bill_id' => $bill->id,
                'receipt_number' => $data['receipt_number'] ?? null,
                'received_at' => $data['received_at'] ?? now()->toDateString(),
                'notes' => $data['notes'] ?? null,
                'created_by' => $userId,
            ]);

            foreach ($data['items'] ?? [] as $row) {
                $billItem = BillItem::query()
                    ->where('bill_id', $bill->id)
                    ->lockForUpdate()
                    ->findOrFail($row['bill_item_id']);

                $accepted = (float) ($row['accepted_quantity'] ?? 0);
                $missing = (float) ($row['missing_quantity'] ?? 0);
                $extra = (float) ($row['extra_quantity'] ?? 0);
                $damaged = (float) ($row['damaged_quantity'] ?? 0);
                $mismatched = (float) ($row['mismatched_quantity'] ?? 0);
                $unitPrice = (float) ($row['unit_price'] ?? $billItem->final_unit_price ?? $billItem->price);

                $remainingOrdered = max(0, (float) $billItem->ordered_quantity - (float) $billItem->received_owned_quantity);
                if ($accepted > $remainingOrdered + 0.0001) {
                    throw new \RuntimeException(__('messages.entered_amount_bigger_than_quantity'));
                }

                $receiptItem = $receipt->items()->create([
                    'bill_item_id' => $billItem->id,
                    'product_id' => $billItem->product_id,
                    'size_id' => $billItem->size_id,
                    'size_color_id' => $billItem->size_color_id,
                    'accepted_quantity' => $accepted,
                    'missing_quantity' => $missing,
                    'extra_quantity' => $extra,
                    'damaged_quantity' => $damaged,
                    'mismatched_quantity' => $mismatched,
                    'unit_price' => $unitPrice,
                    'resolution' => $row['resolution'] ?? null,
                    'reason' => $row['reason'] ?? null,
                    'notes' => $row['notes'] ?? null,
                ]);

                if ($accepted > 0) {
                    $this->costing->addOwnedStock(
                        product: $billItem->product,
                        quantity: $accepted,
                        unitCost: $unitPrice,
                        currency: $bill->currency,
                        sourceType: 'purchase_receipt_item',
                        sourceId: $receiptItem->id,
                        sizeColorId: $billItem->size_color_id,
                        sizeId: $billItem->size_id,
                        userId: $userId,
                        note: 'استلام شراء #'.$bill->id,
                    );
                    $this->recordPurchasePrice($bill, $billItem, $unitPrice, $accepted, $bill->currency, $unitPrice !== (float) $billItem->price, $userId, $receiptItem->id);
                }

                if ($extra > 0) {
                    PurchaseAmanatStock::create([
                        'bill_id' => $bill->id,
                        'bill_item_id' => $billItem->id,
                        'product_id' => $billItem->product_id,
                        'purchase_receipt_item_id' => $receiptItem->id,
                        'quantity' => $extra,
                        'remaining_quantity' => $extra,
                        'status' => 'in_custody',
                        'notes' => $row['notes'] ?? null,
                        'created_by' => $userId,
                    ]);
                }

                $billItem->update([
                    'received_owned_quantity' => (float) $billItem->received_owned_quantity + $accepted,
                    'custody_quantity' => (float) $billItem->custody_quantity + $extra,
                    'damaged_quantity' => (float) $billItem->damaged_quantity + $damaged,
                    'mismatched_quantity' => (float) $billItem->mismatched_quantity + $mismatched,
                    'missing_amount' => (float) ($billItem->missing_amount ?? 0) + $missing,
                    'status' => $this->itemStatusAfterReceiving($billItem, $accepted, $missing, $extra, $damaged, $mismatched),
                ]);
            }

            $this->refreshWorkflowStatus($bill);
            $this->activity->log($bill, 'receipt_created', 'تسجيل استلام شراء', 'تم تسجيل استلام على الفاتورة #'.$bill->id, null, $receipt->load('items')->toArray(), null, 'purchase_receipt', $receipt->id, $userId);

            return $receipt->fresh('items');
        });
    }

    public function purchaseAmanat(PurchaseAmanatStock $amanat, float $quantity, float $unitPrice, ?int $userId = null): PurchaseAmanatStock
    {
        return DB::transaction(function () use ($amanat, $quantity, $unitPrice, $userId) {
            $amanat = PurchaseAmanatStock::query()->lockForUpdate()->findOrFail($amanat->id);
            if ($quantity <= 0 || $quantity > (float) $amanat->remaining_quantity + 0.0001) {
                throw new \RuntimeException(__('messages.entered_amount_bigger_than_quantity'));
            }

            $bill = Bill::query()->lockForUpdate()->findOrFail($amanat->bill_id);
            $billItem = BillItem::query()->lockForUpdate()->findOrFail($amanat->bill_item_id);

            $this->costing->addOwnedStock(
                product: $billItem->product,
                quantity: $quantity,
                unitCost: $unitPrice,
                currency: $bill->currency,
                sourceType: 'purchase_amanat_purchase',
                sourceId: $amanat->id,
                sizeColorId: $billItem->size_color_id,
                sizeId: $billItem->size_id,
                userId: $userId,
                note: 'شراء أمانات من فاتورة #'.$bill->id,
            );

            $billItem->update([
                'received_owned_quantity' => (float) $billItem->received_owned_quantity + $quantity,
                'custody_quantity' => max(0, (float) $billItem->custody_quantity - $quantity),
                'final_unit_price' => $unitPrice,
            ]);

            $amanat->update([
                'remaining_quantity' => max(0, (float) $amanat->remaining_quantity - $quantity),
                'negotiated_unit_price' => $unitPrice,
                'status' => ((float) $amanat->remaining_quantity - $quantity) <= 0.0001 ? 'purchased' : 'partially_purchased',
                'resolved_at' => ((float) $amanat->remaining_quantity - $quantity) <= 0.0001 ? now() : null,
            ]);

            $this->recordPurchasePrice($bill, $billItem, $unitPrice, $quantity, $bill->currency, true, $userId);
            $this->activity->log($bill, 'extra_purchased', 'شراء كمية أمانات', 'تم شراء كمية أمانات بسعر متفاوض عليه', null, $amanat->fresh()->toArray(), null, 'purchase_amanat_stock', $amanat->id, $userId);

            return $amanat->fresh();
        });
    }

    public function finalize(Bill $bill, float $initialPayment = 0, ?int $boxId = null, ?int $userId = null): Bill
    {
        return DB::transaction(function () use ($bill, $initialPayment, $boxId, $userId) {
            $bill = Bill::query()->with('items')->lockForUpdate()->findOrFail($bill->id);
            if ($bill->workflow_status === 'finalized') {
                return $bill;
            }

            $finalTotal = $bill->items->sum(fn (BillItem $item) => (float) $item->received_owned_quantity * (float) ($item->final_unit_price ?? $item->price));
            $bill->update([
                'final_total' => $finalTotal,
                'total' => $finalTotal,
                'workflow_status' => 'finalized',
                'status' => 'finished',
                'finalized_at' => now(),
                'approved_by' => $userId,
            ]);

            if ($finalTotal > 0 && ! DebtTransaction::query()->where('source', 'purchase_invoice')->where('source_id', $bill->id)->exists()) {
                $this->ledger->createTransaction([
                    'customer_id' => $bill->customer_id,
                    'seller_id' => $bill->seller_id,
                    'type' => 'taken',
                    'amount' => $finalTotal,
                    'currency' => $bill->currency,
                    'transaction_date' => now()->toDateString(),
                    'source' => 'purchase_invoice',
                    'source_id' => $bill->id,
                    'note' => 'فاتورة شراء #'.$bill->id,
                ], $userId, false);
            }

            if ($initialPayment > 0) {
                $this->recordPayment($bill->fresh(), $initialPayment, $boxId, 'initial_payment', 'دفعة أولية لفاتورة شراء #'.$bill->id, $userId);
            } else {
                $this->refreshPaymentStatus($bill->fresh());
            }

            $this->activity->log($bill, 'invoice_finalized', 'اعتماد فاتورة شراء', 'تم اعتماد الفاتورة وإثبات الالتزام المالي', null, $bill->fresh()->toArray(), null, 'bill', $bill->id, $userId);

            return $bill->fresh(['items', 'payments']);
        });
    }

    public function recordPayment(Bill $bill, float $amount, ?int $boxId, string $type = 'payment', ?string $note = null, ?int $userId = null): PurchasePayment
    {
        return DB::transaction(function () use ($bill, $amount, $boxId, $type, $note, $userId) {
            $bill = Bill::query()->lockForUpdate()->findOrFail($bill->id);
            $remaining = max(0, (float) $bill->final_total - (float) $bill->paid_amount);
            if ($amount <= 0 || $amount > $remaining + 0.0001) {
                throw new \RuntimeException(__('messages.entered_amount_bigger_than_quantity'));
            }
            if (! $boxId) {
                throw new \RuntimeException(__('messages.must_select_box'));
            }

            $box = Box::query()->lockForUpdate()->findOrFail($boxId);
            if ($this->ledger->normalizeCurrency($box->currency) !== $this->ledger->normalizeCurrency($bill->currency)) {
                throw new \RuntimeException(__('messages.must_be_same_currency_check'));
            }

            $ledgerTx = $this->ledger->createTransaction([
                'customer_id' => $bill->customer_id,
                'seller_id' => $bill->seller_id,
                'type' => 'given',
                'amount' => $amount,
                'currency' => $bill->currency,
                'transaction_date' => now()->toDateString(),
                'box_id' => $boxId,
                'source' => $type === 'initial_payment' ? 'purchase_initial_payment' : 'purchase_payment',
                'source_id' => $bill->id,
                'note' => $note ?? 'دفعة لفاتورة شراء #'.$bill->id,
            ], $userId, true);

            $payment = PurchasePayment::create([
                'bill_id' => $bill->id,
                'seller_id' => $bill->seller_id,
                'customer_id' => $bill->customer_id,
                'box_id' => $boxId,
                'amount' => $amount,
                'currency' => $bill->currency,
                'type' => $type,
                'paid_at' => now()->toDateString(),
                'note' => $note,
                'debt_transaction_id' => $ledgerTx->id,
                'created_by' => $userId,
            ]);

            $bill->update(['paid_amount' => (float) $bill->paid_amount + $amount]);
            $this->refreshPaymentStatus($bill->fresh());
            $this->activity->log($bill, $type === 'initial_payment' ? 'initial_payment_created' : 'supplier_payment_created', 'تسجيل دفعة مورد', 'تم تسجيل دفعة مرتبطة بالصندوق والدفتر', null, $payment->toArray(), null, 'purchase_payment', $payment->id, $userId);

            return $payment;
        });
    }

    public function priceIntelligence(int $productId, ?int $sellerId = null, ?int $customerId = null): array
    {
        $base = PurchasePriceHistory::query()->where('product_id', $productId);
        $supplierLast = null;
        if ($sellerId || $customerId) {
            $supplierLast = (clone $base)
                ->when($sellerId, fn ($q) => $q->where('seller_id', $sellerId))
                ->when($customerId, fn ($q) => $q->where('customer_id', $customerId))
                ->latest('priced_at')
                ->latest('id')
                ->first();
        }

        $latest = (clone $base)->latest('priced_at')->latest('id')->first();
        $lowest = (clone $base)->orderBy('unit_price')->orderByDesc('priced_at')->first();

        return [
            'supplier_last_price' => $supplierLast?->unit_price,
            'latest_price' => $latest?->unit_price,
            'lowest_price' => $lowest?->unit_price,
            'lowest_seller_id' => $lowest?->seller_id,
            'lowest_customer_id' => $lowest?->customer_id,
            'suggested_price' => $lowest?->unit_price ?? $supplierLast?->unit_price ?? $latest?->unit_price,
            'history' => (clone $base)->latest('priced_at')->latest('id')->limit(30)->get(),
        ];
    }

    private function recordPurchasePrice(Bill $bill, BillItem $item, float $price, float $quantity, string $currency, bool $manualOverride, ?int $userId, ?int $receiptItemId = null): void
    {
        PurchasePriceHistory::create([
            'product_id' => $item->product_id,
            'seller_id' => $bill->seller_id,
            'customer_id' => $bill->customer_id,
            'bill_id' => $bill->id,
            'bill_item_id' => $item->id,
            'purchase_receipt_item_id' => $receiptItemId,
            'unit_price' => $price,
            'quantity' => $quantity,
            'currency' => $currency,
            'priced_at' => now()->toDateString(),
            'manual_override' => $manualOverride,
            'created_by' => $userId,
        ]);
    }

    private function updateLatestPriceCache(Bill $bill, int $productId, float $price): void
    {
        if (! $bill->seller_id) {
            return;
        }

        PurchaseProduct::query()->updateOrCreate(
            ['seller_id' => $bill->seller_id, 'product_id' => $productId],
            ['price' => $price]
        );
    }

    private function refreshWorkflowStatus(Bill $bill): void
    {
        $bill = $bill->fresh('items');
        $hasRemaining = $bill->items->contains(fn (BillItem $item) => (float) $item->received_owned_quantity < (float) $item->ordered_quantity);
        $hasCustody = $bill->items->contains(fn (BillItem $item) => (float) $item->custody_quantity > 0);

        $bill->update([
            'workflow_status' => $hasRemaining || $hasCustody ? 'partially_received' : 'received',
        ]);
    }

    private function refreshPaymentStatus(Bill $bill): void
    {
        $paid = (float) $bill->paid_amount;
        $total = (float) $bill->final_total;
        $status = $paid <= 0.0001 ? 'unpaid' : ($paid + 0.0001 >= $total ? 'paid' : 'partially_paid');
        $bill->update(['payment_status' => $status]);
    }

    private function itemStatusAfterReceiving(BillItem $item, float $accepted, float $missing, float $extra, float $damaged, float $mismatched): string
    {
        if ($extra > 0) {
            return 'extra';
        }
        if ($damaged > 0) {
            return 'damaged';
        }
        if ($mismatched > 0) {
            return 'not_compatible';
        }
        if ($missing > 0) {
            return 'missing';
        }

        return ((float) $item->received_owned_quantity + $accepted) >= (float) $item->ordered_quantity
            ? 'finished'
            : 'unfinished';
    }

    private function normalizeCurrency(?string $currency): string
    {
        return $this->ledger->normalizeCurrency($currency);
    }
}
