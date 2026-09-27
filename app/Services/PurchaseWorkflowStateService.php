<?php

namespace App\Services;

use App\Models\Bill;
use App\Models\BillItem;
use App\Models\InventoryCostLayer;
use App\Models\PurchaseAmanatStock;
use App\Models\PurchaseIssueResolution;
use App\Models\PurchaseReceiptItem;
use Illuminate\Support\Facades\Schema;

class PurchaseWorkflowStateService
{
    private const EPSILON = 0.0001;

    public function orderedReceivedQuantity(BillItem $item): float
    {
        $receiptItems = PurchaseReceiptItem::query()->where('bill_item_id', $item->id);
        $acceptedAtReceiving = (float) (clone $receiptItems)->sum('accepted_quantity');

        $issueResolutions = PurchaseIssueResolution::query()->where('bill_item_id', $item->id);
        $acceptedFromIssues = (float) (clone $issueResolutions)
            ->whereIn('issue_type', ['damaged', 'mismatched'])
            ->whereIn('resolution', ['accept_with_discount', 'accept_negotiated_price'])
            ->sum('quantity');

        $derived = $acceptedAtReceiving + $acceptedFromIssues;
        $hasAuditableRecords = (clone $receiptItems)->exists()
            || (clone $issueResolutions)->exists()
            || (Schema::hasTable('purchase_amanat_stocks')
                && PurchaseAmanatStock::query()->where('bill_item_id', $item->id)->exists());

        // Older rows may predate receipt tracking. Preserve their owned stock as
        // ordered stock only when no auditable receiving rows exist.
        if (! $hasAuditableRecords
            && $derived <= self::EPSILON
            && (float) $item->received_owned_quantity > self::EPSILON) {
            return min(
                (float) ($item->ordered_quantity ?? $item->quantity),
                (float) $item->received_owned_quantity,
            );
        }

        return $derived;
    }

    public function openIssueQuantity(BillItem $item): float
    {
        return (float) ($item->missing_amount ?? 0)
            + (float) ($item->damaged_quantity ?? 0)
            + (float) ($item->mismatched_quantity ?? 0);
    }

    public function remainingToReceive(BillItem $item): float
    {
        return max(
            0,
            (float) ($item->ordered_quantity ?? $item->quantity)
                - $this->orderedReceivedQuantity($item)
                - $this->openIssueQuantity($item),
        );
    }

    public function refresh(Bill $bill): Bill
    {
        $bill = Bill::query()->with(['items', 'receipts'])->findOrFail($bill->id);
        if (in_array($bill->workflow_status, ['finalized', 'cancelled'], true)) {
            return $bill;
        }

        $hasRemaining = false;
        $hasIssues = false;
        $hasCustody = false;
        $hasReceivingActivity = $bill->receipts->isNotEmpty();

        foreach ($bill->items as $item) {
            $orderedReceived = $this->orderedReceivedQuantity($item);
            $openIssues = $this->openIssueQuantity($item);
            $remaining = max(
                0,
                (float) ($item->ordered_quantity ?? $item->quantity)
                    - $orderedReceived
                    - $openIssues,
            );
            $itemHasIssues = $openIssues > self::EPSILON;
            $itemHasCustody = (float) $item->custody_quantity > self::EPSILON;

            $status = match (true) {
                (float) $item->damaged_quantity > self::EPSILON => 'damaged',
                (float) $item->mismatched_quantity > self::EPSILON => 'not_compatible',
                (float) ($item->missing_amount ?? 0) > self::EPSILON => 'missing',
                $itemHasCustody => 'extra',
                $remaining > self::EPSILON => 'unfinished',
                default => 'finished',
            };

            if ($item->status !== $status) {
                $item->update(['status' => $status]);
            }

            $hasRemaining = $hasRemaining || $remaining > self::EPSILON;
            $hasIssues = $hasIssues || $itemHasIssues;
            $hasCustody = $hasCustody || $itemHasCustody;
            $hasReceivingActivity = $hasReceivingActivity
                || $orderedReceived > self::EPSILON
                || (float) $item->received_owned_quantity > self::EPSILON;
        }

        $workflowStatus = match (true) {
            $hasIssues || $hasCustody => 'receiving_issues',
            $hasRemaining && $hasReceivingActivity => 'partially_received',
            $hasRemaining => 'awaiting_receiving',
            default => 'received',
        };

        $bill->update(['workflow_status' => $workflowStatus]);

        return $bill->fresh(['items', 'receipts']);
    }

    public function assertReadyForFinalization(Bill $bill): Bill
    {
        $bill = $this->refresh($bill);
        if ($bill->items->isEmpty()) {
            throw new \RuntimeException('لا يمكن اعتماد فاتورة بدون أصناف.');
        }

        foreach ($bill->items as $item) {
            if ($this->openIssueQuantity($item) > self::EPSILON) {
                throw new \RuntimeException('لا يمكن اعتماد الفاتورة قبل معالجة فروقات الاستلام.');
            }
            if ((float) $item->custody_quantity > self::EPSILON) {
                throw new \RuntimeException('لا يمكن اعتماد الفاتورة قبل شراء الزيادة أو إرجاعها للمورد.');
            }
            if ($this->remainingToReceive($item) > self::EPSILON) {
                throw new \RuntimeException('لا يمكن اعتماد الفاتورة قبل اكتمال الاستلام.');
            }
        }

        if ($bill->items->sum(fn (BillItem $item) => (float) $item->received_owned_quantity) <= self::EPSILON) {
            throw new \RuntimeException('لا يمكن اعتماد فاتورة لم يتم استلام أي بضاعة مملوكة فيها.');
        }

        return $bill;
    }

    public function recognizedTotal(Bill $bill): float
    {
        $itemIds = $bill->items()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $receiptItemIds = PurchaseReceiptItem::query()
            ->whereIn('bill_item_id', $itemIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $amanatIds = Schema::hasTable('purchase_amanat_stocks')
            ? PurchaseAmanatStock::query()
                ->where('bill_id', $bill->id)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all()
            : [];

        $layers = InventoryCostLayer::query()
            ->where(function ($query) use ($receiptItemIds, $itemIds, $amanatIds) {
                if ($receiptItemIds !== []) {
                    $query->orWhere(function ($source) use ($receiptItemIds) {
                        $source->where('source_type', 'purchase_receipt_item')
                            ->whereIn('source_id', $receiptItemIds);
                    });
                }
                if ($itemIds !== []) {
                    $query->orWhere(function ($source) use ($itemIds) {
                        $source->where('source_type', 'purchase_issue_resolution')
                            ->whereIn('source_id', $itemIds);
                    });
                }
                if ($amanatIds !== []) {
                    $query->orWhere(function ($source) use ($amanatIds) {
                        $source->where('source_type', 'purchase_amanat_purchase')
                            ->whereIn('source_id', $amanatIds);
                    });
                }
            })
            ->get(['quantity', 'unit_cost']);

        if ($layers->isNotEmpty()) {
            return (float) $layers->sum(
                fn (InventoryCostLayer $layer) => (float) $layer->quantity * (float) $layer->unit_cost
            );
        }

        $bill->loadMissing('items');

        return (float) $bill->items->sum(
            fn (BillItem $item) => (float) $item->received_owned_quantity
                * (float) ($item->final_unit_price ?? $item->price)
        );
    }
}
