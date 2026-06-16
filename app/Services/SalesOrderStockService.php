<?php

namespace App\Services;

use App\Enums\SalesOrderStatus;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesOrderStockService
{
    public function __construct(
        protected ProductStockService $productStockService
    ) {}

    /**
     * @return list<string>
     */
    public function reservingStatuses(): array
    {
        return [
            SalesOrderStatus::Confirmed->value,
            SalesOrderStatus::Ready->value,
            SalesOrderStatus::Postponed->value,
            SalesOrderStatus::Review->value,
        ];
    }

    public function reservedQuantity(
        int $productId,
        ?int $sizeColorId = null,
        ?int $excludeOrderId = null
    ): int {
        $query = SalesOrderItem::query()
            ->where('product_id', $productId)
            ->whereHas('salesOrder', function ($q) use ($excludeOrderId) {
                $q->whereIn('status', $this->reservingStatuses());
                if ($excludeOrderId) {
                    $q->where('id', '!=', $excludeOrderId);
                }
            });

        if ($sizeColorId !== null && $sizeColorId > 0) {
            $query->where('size_color_id', $sizeColorId);
        } else {
            $query->where(function ($q) {
                $q->whereNull('size_color_id')->orWhere('size_color_id', 0);
            });
        }

        return (int) $query->sum(DB::raw('GREATEST(reserved_qty - dispatched_qty, 0)'));
    }

    public function availableForOrder(Product $product, int $quantity, ?int $sizeColorId = null, ?int $excludeOrderId = null): int
    {
        $physical = $this->productStockService->resolveAvailableStock($product, $sizeColorId);
        $reserved = $this->reservedQuantity((int) $product->id, $sizeColorId, $excludeOrderId);

        return max(0, $physical - $reserved);
    }

    public function assertItemCanReserve(SalesOrderItem $item, ?int $excludeOrderId = null): void
    {
        $product = Product::query()->find($item->product_id);
        if (! $product) {
            throw ValidationException::withMessages([
                'items' => [__('messages.sales_order_product_not_found')],
            ]);
        }

        $sizeColorId = $item->size_color_id ? (int) $item->size_color_id : null;
        $available = $this->availableForOrder($product, (int) $item->quantity, $sizeColorId, $excludeOrderId);

        if ($available < (int) $item->quantity) {
            throw ValidationException::withMessages([
                'items' => [__('messages.sales_order_insufficient_stock', [
                    'product' => $item->product_name ?? $product->nameAr,
                ])],
            ]);
        }
    }

    public function reserveOrder(SalesOrder $order): void
    {
        $order->loadMissing('items.product');

        foreach ($order->items as $item) {
            if ($item->is_hidden) {
                continue;
            }

            $this->assertItemCanReserve($item, (int) $order->id);
            $item->update(['reserved_qty' => (int) $item->quantity]);
        }
    }

    public function releaseOrder(SalesOrder $order): void
    {
        SalesOrderItem::query()
            ->where('sales_order_id', $order->id)
            ->update(['reserved_qty' => 0]);
    }

    public function syncReservationsAfterEdit(SalesOrder $order): void
    {
        if (! $order->statusEnum()->reservesStock()) {
            return;
        }

        $this->releaseOrder($order);
        $this->reserveOrder($order->fresh(['items.product']));
    }

    public function dispatchOrder(SalesOrder $order, ?int $userId = null): void
    {
        $order->loadMissing('items.product');

        foreach ($order->items as $item) {
            if ($item->is_hidden || (int) $item->dispatched_qty >= (int) $item->quantity) {
                continue;
            }

            $qty = (int) $item->quantity - (int) $item->dispatched_qty;
            $product = Product::query()->findOrFail($item->product_id);
            $sizeColorId = $item->size_color_id ? (int) $item->size_color_id : null;

            $this->productStockService->deductForSale(
                product: $product,
                quantity: $qty,
                sizeColorId: $sizeColorId > 0 ? $sizeColorId : null,
                sizeId: $item->size_id ? (int) $item->size_id : null,
                referenceType: 'sales_order',
                referenceId: (int) $order->id,
                note: 'خصم مخزون — طلبية #'.$order->id,
                userId: $userId,
            );

            $item->update([
                'dispatched_qty' => (int) $item->quantity,
                'reserved_qty' => 0,
            ]);
        }
    }

    public function restoreDispatchedOrder(SalesOrder $order, ?int $userId = null): void
    {
        $order->loadMissing('items.product');

        foreach ($order->items as $item) {
            if ((int) $item->dispatched_qty <= 0) {
                continue;
            }

            $product = Product::query()->find($item->product_id);
            if (! $product) {
                continue;
            }

            $qty = (int) $item->dispatched_qty;
            $sizeColorId = $item->size_color_id ? (int) $item->size_color_id : null;

            $this->productStockService->restoreForSale(
                product: $product,
                quantity: $qty,
                sizeColorId: $sizeColorId > 0 ? $sizeColorId : null,
                sizeId: $item->size_id ? (int) $item->size_id : null,
                referenceType: 'sales_order',
                referenceId: (int) $order->id,
                note: 'إرجاع مخزون — طلبية #'.$order->id,
                userId: $userId,
            );

            $item->update([
                'dispatched_qty' => 0,
                'reserved_qty' => 0,
            ]);
        }
    }

    public function restorePartialDispatched(SalesOrderItem $item, int $qty, ?int $userId = null): void
    {
        if ($qty <= 0) {
            return;
        }

        $product = Product::query()->find($item->product_id);
        if (! $product) {
            return;
        }

        $sizeColorId = $item->size_color_id ? (int) $item->size_color_id : null;

        $this->productStockService->restoreForSale(
            product: $product,
            quantity: $qty,
            sizeColorId: $sizeColorId > 0 ? $sizeColorId : null,
            sizeId: $item->size_id ? (int) $item->size_id : null,
            referenceType: 'sales_order',
            referenceId: (int) $item->sales_order_id,
            note: 'إرجاع جزئي — طلبية #'.$item->sales_order_id,
            userId: $userId,
        );
    }
}
