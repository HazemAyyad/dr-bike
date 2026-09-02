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
            SalesOrderStatus::Unconfirmed->value,
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
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_items.sales_order_id')
            ->where('sales_order_items.product_id', $productId)
            ->where('sales_orders.reserves_stock', true)
            ->whereIn('sales_orders.status', $this->reservingStatuses());

        if ($excludeOrderId) {
            $query->where('sales_orders.id', '!=', $excludeOrderId);
        }

        if ($sizeColorId !== null && $sizeColorId > 0) {
            $query->where('sales_order_items.size_color_id', $sizeColorId);
        } else {
            $query->where(function ($q) {
                $q->whereNull('sales_order_items.size_color_id')
                    ->orWhere('sales_order_items.size_color_id', 0);
            });
        }

        $unconfirmed = SalesOrderStatus::Unconfirmed->value;

        return (int) $query->sum(DB::raw(
            "GREATEST(
                GREATEST(
                    CAST(sales_order_items.reserved_qty AS SIGNED),
                    CASE
                        WHEN sales_orders.status = '{$unconfirmed}'
                            AND CAST(sales_order_items.reserved_qty AS SIGNED) = 0
                        THEN CAST(sales_order_items.quantity AS SIGNED)
                        ELSE CAST(sales_order_items.reserved_qty AS SIGNED)
                    END
                ) - CAST(sales_order_items.dispatched_qty AS SIGNED),
                0
            )"
        ));
    }

    public function reservedQuantityForProduct(
        int $productId,
        ?int $excludeOrderId = null
    ): int {
        $query = SalesOrderItem::query()
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_items.sales_order_id')
            ->where('sales_order_items.product_id', $productId)
            ->where('sales_orders.reserves_stock', true)
            ->where('sales_order_items.is_hidden', false)
            ->whereIn('sales_orders.status', $this->reservingStatuses());

        if ($excludeOrderId) {
            $query->where('sales_orders.id', '!=', $excludeOrderId);
        }

        $unconfirmed = SalesOrderStatus::Unconfirmed->value;

        return (int) $query->sum(DB::raw(
            "GREATEST(
                GREATEST(
                    CAST(sales_order_items.reserved_qty AS SIGNED),
                    CASE
                        WHEN sales_orders.status = '{$unconfirmed}'
                            AND CAST(sales_order_items.reserved_qty AS SIGNED) = 0
                        THEN CAST(sales_order_items.quantity AS SIGNED)
                        ELSE CAST(sales_order_items.reserved_qty AS SIGNED)
                    END
                ) - CAST(sales_order_items.dispatched_qty AS SIGNED),
                0
            )"
        ));
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

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    public function analyzeItemsStockImpact(array $items, ?int $excludeOrderId = null): array
    {
        $aggregated = [];

        foreach ($items as $item) {
            if (! empty($item['is_hidden'])) {
                continue;
            }

            $productId = (int) ($item['product_id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }

            $sizeColorId = ! empty($item['size_color_id']) ? (int) $item['size_color_id'] : null;
            $key = $productId.'_'.($sizeColorId ?? 0);
            $aggregated[$key] = [
                'product_id' => $productId,
                'size_color_id' => $sizeColorId,
                'quantity' => (int) ($aggregated[$key]['quantity'] ?? 0) + (int) ($item['quantity'] ?? 0),
                'product_name' => $item['product_name'] ?? ($aggregated[$key]['product_name'] ?? null),
            ];
        }

        $conflicts = [];

        foreach ($aggregated as $row) {
            $product = Product::query()->find($row['product_id']);
            if (! $product) {
                continue;
            }

            $sizeColorId = $row['size_color_id'];
            $physical = $this->productStockService->resolveAvailableStock($product, $sizeColorId);
            $reserved = $this->reservedQuantity($row['product_id'], $sizeColorId, $excludeOrderId);
            $available = $physical - $reserved;
            $requested = (int) $row['quantity'];

            if ($available < $requested) {
                $conflicts[] = [
                    'product_id' => $row['product_id'],
                    'product_name' => $row['product_name'] ?? $product->nameAr,
                    'size_color_id' => $sizeColorId,
                    'physical_stock' => $physical,
                    'reserved_by_others' => $reserved,
                    'available' => max(0, $available),
                    'requested_qty' => $requested,
                    'deficit' => $requested - max(0, $available),
                    'reserving_orders' => $this->reservingOrdersForProduct(
                        $row['product_id'],
                        $sizeColorId,
                        $excludeOrderId
                    ),
                ];
            }
        }

        return $conflicts;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function analyzeOrderStockImpact(SalesOrder $order, ?int $excludeOrderId = null): array
    {
        $order->loadMissing('items');

        $items = $order->items
            ->where('is_hidden', false)
            ->map(fn (SalesOrderItem $item) => [
                'product_id' => (int) $item->product_id,
                'size_color_id' => $item->size_color_id ? (int) $item->size_color_id : null,
                'quantity' => (int) $item->quantity,
                'product_name' => $item->product_name,
                'is_hidden' => (bool) $item->is_hidden,
            ])
            ->values()
            ->all();

        return $this->analyzeItemsStockImpact(
            $items,
            $excludeOrderId ?? (int) $order->id
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function reservingOrdersForProduct(
        int $productId,
        ?int $sizeColorId = null,
        ?int $excludeOrderId = null
    ): array {
        $query = SalesOrderItem::query()
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_items.sales_order_id')
            ->leftJoin('customers', 'customers.id', '=', 'sales_orders.customer_id')
            ->where('sales_order_items.product_id', $productId)
            ->where('sales_orders.reserves_stock', true)
            ->where('sales_order_items.is_hidden', false)
            ->whereIn('sales_orders.status', $this->reservingStatuses());

        if ($excludeOrderId) {
            $query->where('sales_orders.id', '!=', $excludeOrderId);
        }

        if ($sizeColorId !== null && $sizeColorId > 0) {
            $query->where('sales_order_items.size_color_id', $sizeColorId);
        } else {
            $query->where(function ($q) {
                $q->whereNull('sales_order_items.size_color_id')
                    ->orWhere('sales_order_items.size_color_id', 0);
            });
        }

        $rows = $query->get([
            'sales_orders.id as order_id',
            'sales_orders.serial_number',
            'sales_orders.customer_name',
            'sales_orders.customer_id',
            'sales_orders.status as order_status',
            'customers.type as customer_type',
            'sales_order_items.quantity',
            'sales_order_items.reserved_qty',
            'sales_order_items.dispatched_qty',
        ]);

        $grouped = [];

        foreach ($rows as $row) {
            $net = $this->effectiveReservedNet(
                (int) $row->reserved_qty,
                (int) $row->quantity,
                (string) $row->order_status,
                (int) $row->dispatched_qty
            );

            if ($net <= 0) {
                continue;
            }

            $orderId = (int) $row->order_id;

            if (! isset($grouped[$orderId])) {
                $grouped[$orderId] = [
                    'order_id' => $orderId,
                    'serial_number' => $row->serial_number,
                    'customer_name' => $row->customer_name,
                    'party_type' => $this->resolvePartyType(
                        $row->customer_id ? (int) $row->customer_id : null,
                        $row->customer_type
                    ),
                    'status' => $row->order_status,
                    'reserved_qty' => 0,
                ];
            }

            $grouped[$orderId]['reserved_qty'] += $net;
        }

        return array_values($grouped);
    }

    private function effectiveReservedNet(
        int $reservedQty,
        int $quantity,
        string $orderStatus,
        int $dispatchedQty
    ): int {
        $reserved = $reservedQty;
        if ($reserved <= 0 && $orderStatus === SalesOrderStatus::Unconfirmed->value) {
            $reserved = $quantity;
        }

        return max(0, $reserved - $dispatchedQty);
    }

    private function resolvePartyType(?int $customerId, mixed $customerType): ?string
    {
        if (! $customerId) {
            return null;
        }

        $type = strtolower(trim((string) $customerType));
        $traderTypes = ['trader', 'تاجر', 'seller', 'مورد', 'supplier'];

        return in_array($type, $traderTypes, true) ? 'trader' : 'customer';
    }

    /**
     * @param  list<int>  $productIds
     * @return list<array<string, mixed>>
     */
    public function bulkAvailability(array $productIds, ?int $excludeOrderId = null): array
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        $rows = [];

        foreach ($productIds as $productId) {
            $product = Product::query()->with(['sizes.colorSizes'])->find($productId);
            if (! $product) {
                continue;
            }

            $hasVariants = $this->productStockService->productHasVariants($product);

            if ($hasVariants) {
                $totalPhysical = 0;
                $totalReserved = 0;

                foreach ($product->sizes ?? [] as $size) {
                    foreach ($size->colorSizes ?? [] as $variant) {
                        $sizeColorId = (int) $variant->id;
                        $physical = (int) $variant->stock;
                        $reserved = $this->reservedQuantity($productId, $sizeColorId, $excludeOrderId);
                        $variantTotalReserved = $this->reservedQuantity($productId, $sizeColorId, null);
                        $totalPhysical += $physical;
                        $totalReserved += $reserved;

                        $rows[] = [
                            'product_id' => $productId,
                            'size_color_id' => $sizeColorId,
                            'physical_stock' => $physical,
                            'reserved_qty' => $reserved,
                            'total_reserved_qty' => $variantTotalReserved,
                            'available_qty' => max(0, $physical - $reserved),
                        ];
                    }
                }

                $productReserved = $this->reservedQuantityForProduct($productId, $excludeOrderId);
                $totalReserved = $this->reservedQuantityForProduct($productId, null);

                $rows[] = [
                    'product_id' => $productId,
                    'size_color_id' => null,
                    'physical_stock' => $totalPhysical,
                    'reserved_qty' => $productReserved,
                    'total_reserved_qty' => $totalReserved,
                    'available_qty' => max(0, $totalPhysical - $productReserved),
                    'is_aggregate' => true,
                ];
            } else {
                $physical = $this->productStockService->resolveAvailableStock($product, null);
                $reserved = $this->reservedQuantityForProduct($productId, $excludeOrderId);
                $totalReserved = $this->reservedQuantityForProduct($productId, null);

                $rows[] = [
                    'product_id' => $productId,
                    'size_color_id' => null,
                    'physical_stock' => $physical,
                    'reserved_qty' => $reserved,
                    'total_reserved_qty' => $totalReserved,
                    'available_qty' => max(0, $physical - $reserved),
                ];
            }
        }

        return $rows;
    }

    public function reserveOrder(SalesOrder $order, bool $allowNegative = false): void
    {
        if (! $order->reserves_stock) {
            $this->releaseOrder($order);

            return;
        }

        $order->loadMissing('items.product');

        foreach ($order->items as $item) {
            if ($item->is_hidden) {
                continue;
            }

            if (! $allowNegative) {
                $this->assertItemCanReserve($item, (int) $order->id);
            }

            $item->update(['reserved_qty' => (int) $item->quantity]);
        }
    }

    public function releaseOrder(SalesOrder $order): void
    {
        SalesOrderItem::query()
            ->where('sales_order_id', $order->id)
            ->update(['reserved_qty' => 0]);
    }

    public function syncReservationsAfterEdit(
        SalesOrder $order,
        ?int $userId = null,
        bool $allowNegative = false
    ): void {
        if ($order->stock_deducted_at) {
            $this->restoreDispatchedOrder($order, $userId);
            $this->dispatchOrder($order->fresh(['items.product']), $userId);

            return;
        }

        if (! $order->reserves_stock) {
            $this->releaseOrder($order);

            return;
        }

        if (! $order->statusEnum()->reservesStock()) {
            return;
        }

        $this->releaseOrder($order);
        $this->reserveOrder($order->fresh(['items.product']), $allowNegative);
    }

    public function dispatchOrder(
        SalesOrder $order,
        ?int $userId = null,
        bool $allowNegative = false
    ): void
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
                allowNegative: $allowNegative,
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
