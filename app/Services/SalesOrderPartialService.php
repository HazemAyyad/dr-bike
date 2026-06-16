<?php

namespace App\Services;

use App\Enums\SalesOrderStatus;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderDelivery;
use App\Models\SalesOrderItem;
use App\Models\SalesReturn;
use App\Models\SalesReturnItem;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesOrderPartialService
{
    public function __construct(
        protected SalesOrderStockService $stockService,
        protected SalesOrderFulfillmentService $fulfillmentService,
        protected SalesOrderNotificationService $notifications,
    ) {}

    /**
     * @param  array<int, array{item_id: int, quantity: int}>  $lines
     */
    public function partialDeliver(User $user, int $orderId, array $lines, array $payload = []): SalesOrder
    {
        $order = SalesOrder::query()->with(['items'])->findOrFail($orderId);
        $this->assertTransition($order, [
            SalesOrderStatus::WithDelivery,
            SalesOrderStatus::PartialDelivered,
            SalesOrderStatus::Review,
        ]);

        $data = validator($payload, [
            'payment_amount' => 'nullable|numeric|min:0',
            'payment_box_id' => 'nullable|integer|exists:boxes,id',
        ])->validate();

        $this->fulfillmentService->assertHasRequiredMedia($order);

        $deliverMap = $this->parseItemLines($order, $lines, 'deliver');

        return DB::transaction(function () use ($user, $order, $deliverMap, $data) {
            $from = $order->status;
            $deliveredSubtotal = $this->applyDeliveredQuantities($order, $deliverMap);
            $portion = $order->subtotal > 0 ? $deliveredSubtotal / (float) $order->subtotal : 1.0;
            $deliveryFee = round((float) $order->customer_delivery_fee * $portion, 2);
            $discount = round((float) $order->discount * $portion, 2);
            $partialTotal = max(0, round($deliveredSubtotal + $deliveryFee - $discount, 2));

            $itemsForSale = $this->buildItemSubset($order, $deliverMap);
            $instantSale = $this->fulfillmentService->createInstantSaleForItems(
                $order,
                $itemsForSale,
                $user,
                $partialTotal,
                $deliveryFee,
                $discount,
                $data
            );

            $fullyDelivered = $this->isFullyDelivered($order);
            $newStatus = $fullyDelivered
                ? SalesOrderStatus::Delivered->value
                : SalesOrderStatus::Review->value;

            $updates = [
                'status' => $newStatus,
                'updated_by' => $user->id,
            ];

            if ($fullyDelivered) {
                $updates['financial_posted_at'] = now();
                if (! $order->instant_sale_id) {
                    $updates['instant_sale_id'] = $instantSale->id;
                }

                SalesOrderDelivery::query()
                    ->where('sales_order_id', $order->id)
                    ->latest('id')
                    ->limit(1)
                    ->update(['delivered_at' => now()]);
            }

            $order->update($updates);
            $this->logStatus($order, $from, $newStatus, 'توصيل جزئي', $user->id);
            $this->notifications->notifyStatusChange($order->fresh(), $from, $newStatus, $user);

            return $order->fresh();
        });
    }

    public function createFollowUpOrder(User $user, int $orderId): SalesOrder
    {
        $order = SalesOrder::query()->with(['items'])->findOrFail($orderId);
        $this->assertTransition($order, [
            SalesOrderStatus::Review,
            SalesOrderStatus::PartialDelivered,
            SalesOrderStatus::AlternativeReturn,
        ]);

        $remaining = $this->remainingItems($order);
        if ($remaining === []) {
            throw ValidationException::withMessages([
                'order' => [__('messages.sales_order_no_remaining_items')],
            ]);
        }

        return DB::transaction(function () use ($user, $order, $remaining) {
            $subtotal = 0.0;
            foreach ($remaining as $row) {
                $subtotal += round($row['quantity'] * $row['unit_price'], 2);
            }

            $deliveryFee = (float) $order->customer_delivery_fee;
            $discount = 0.0;
            $total = max(0, round($subtotal + $deliveryFee - $discount, 2));

            $child = SalesOrder::create([
                'customer_id' => $order->customer_id,
                'customer_name' => $order->customer_name,
                'customer_phone' => $order->customer_phone,
                'customer_address' => $order->customer_address,
                'city_id' => $order->city_id,
                'status' => SalesOrderStatus::Unconfirmed->value,
                'parent_order_id' => $order->id,
                'root_order_id' => $order->root_order_id ?? $order->id,
                'payment_type' => $order->payment_type,
                'payment_amount' => $order->payment_amount,
                'customer_delivery_fee' => $deliveryFee,
                'subtotal' => $subtotal,
                'discount' => $discount,
                'total' => $total,
                'notes' => __('messages.sales_order_follow_up_note', [
                    'serial' => $order->serial_number ?? '#'.$order->id,
                ]),
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);

            app(DocumentSerialService::class)->assignToModel(
                $child,
                DocumentSerialService::TYPE_SALES_ORDER,
                'serial_number',
                $child->created_at
            );

            foreach ($remaining as $row) {
                SalesOrderItem::create([
                    'sales_order_id' => $child->id,
                    'product_id' => $row['product_id'],
                    'size_id' => $row['size_id'],
                    'size_color_id' => $row['size_color_id'],
                    'product_name' => $row['product_name'],
                    'quantity' => $row['quantity'],
                    'unit_price' => $row['unit_price'],
                    'line_total' => round($row['quantity'] * $row['unit_price'], 2),
                ]);
            }

            $this->logStatus($child, null, SalesOrderStatus::Unconfirmed->value, 'طلبية متابعة', $user->id);
            $this->logStatus(
                $order,
                $order->status,
                $order->status,
                __('messages.sales_order_follow_up_created', [
                    'serial' => $child->serial_number ?? '#'.$child->id,
                ]),
                $user->id
            );

            return $child->fresh([
                'items',
                'city:id,name_ar,name_en',
            ]);
        });
    }

    public function partialReturn(User $user, int $orderId, array $lines, ?string $note = null): SalesOrder
    {
        $order = SalesOrder::query()->with(['items.product'])->findOrFail($orderId);
        $this->assertTransition($order, [SalesOrderStatus::WithDelivery]);

        return DB::transaction(function () use ($user, $order, $lines, $note) {
            $this->applyPartialReturn($user, $order, $lines, $note, true);

            return $order->fresh();
        });
    }

    /**
     * @param  array<int, array{item_id: int, quantity: int}>  $lines
     */
    private function applyPartialReturn(
        User $user,
        SalesOrder $order,
        array $lines,
        ?string $note,
        bool $notify
    ): void {
        $returnMap = $this->parseItemLines($order, $lines, 'return');
        $returnTotal = 0.0;
        $returnRows = [];

        foreach ($returnMap as $itemId => $qty) {
            /** @var SalesOrderItem $item */
            $item = $order->items->firstWhere('id', $itemId);

            $this->stockService->restorePartialDispatched($item, $qty, (int) $user->id);
            $lineTotal = round($qty * (float) $item->unit_price, 2);
            $returnTotal += $lineTotal;

            $item->update([
                'returned_qty' => (int) $item->returned_qty + $qty,
                'dispatched_qty' => (int) $item->dispatched_qty - $qty,
            ]);

            $returnRows[] = [
                'sales_order_item_id' => $item->id,
                'product_id' => $item->product_id,
                'size_id' => $item->size_id,
                'size_color_id' => $item->size_color_id,
                'product_name' => $item->product_name,
                'quantity' => $qty,
                'unit_price' => (float) $item->unit_price,
                'line_total' => $lineTotal,
            ];
        }

        $salesReturn = SalesReturn::create([
            'sales_order_id' => $order->id,
            'return_type' => 'partial',
            'total_amount' => $returnTotal,
            'note' => $note,
            'created_by' => $user->id,
        ]);

        foreach ($returnRows as $row) {
            SalesReturnItem::create(array_merge($row, ['sales_return_id' => $salesReturn->id]));
        }

        $from = $order->status;
        $newStatus = $this->isFullyReturnedFromCarrier($order->fresh(['items']))
            ? SalesOrderStatus::Returned->value
            : SalesOrderStatus::PartialReturn->value;

        $order->update(['status' => $newStatus, 'updated_by' => $user->id]);
        $this->logStatus($order, $from, $newStatus, $note ?? 'راجع جزئي', $user->id);

        if ($notify) {
            $this->notifications->notifyStatusChange($order->fresh(), $from, $newStatus, $user, $note);
        }
    }

    /**
     * @param  array<int, array{item_id: int, quantity: int}>  $returnLines
     * @param  array<int, array{product_id: int, quantity: int, unit_price: float, size_id?: int, size_color_id?: int}>  $replacementLines
     */
    public function alternativeReturn(
        User $user,
        int $orderId,
        array $returnLines,
        array $replacementLines,
        ?string $note = null
    ): SalesOrder {
        $order = SalesOrder::query()->with(['items.product'])->findOrFail($orderId);
        $this->assertTransition($order, [
            SalesOrderStatus::WithDelivery,
            SalesOrderStatus::Review,
            SalesOrderStatus::PartialDelivered,
        ]);

        if ($replacementLines === []) {
            throw ValidationException::withMessages([
                'replacement_items' => [__('messages.sales_order_replacement_required')],
            ]);
        }

        return DB::transaction(function () use ($user, $order, $returnLines, $replacementLines, $note) {
            if ($returnLines !== []) {
                $this->applyPartialReturn($user, $order, $returnLines, $note, false);
                $order = $order->fresh(['items.product']);
            }

            $subtotal = 0.0;
            $itemsPayload = [];
            foreach ($replacementLines as $line) {
                $product = Product::query()->find($line['product_id']);
                $qty = (int) $line['quantity'];
                $price = (float) $line['unit_price'];
                $subtotal += round($qty * $price, 2);
                $itemsPayload[] = [
                    'product_id' => (int) $line['product_id'],
                    'size_id' => $line['size_id'] ?? null,
                    'size_color_id' => $line['size_color_id'] ?? null,
                    'quantity' => $qty,
                    'unit_price' => $price,
                ];
            }

            $deliveryFee = (float) $order->customer_delivery_fee;
            $total = max(0, round($subtotal + $deliveryFee, 2));

            $child = SalesOrder::create([
                'customer_id' => $order->customer_id,
                'customer_name' => $order->customer_name,
                'customer_phone' => $order->customer_phone,
                'customer_address' => $order->customer_address,
                'city_id' => $order->city_id,
                'status' => SalesOrderStatus::Unconfirmed->value,
                'parent_order_id' => $order->id,
                'root_order_id' => $order->root_order_id ?? $order->id,
                'payment_type' => $order->payment_type,
                'customer_delivery_fee' => $deliveryFee,
                'subtotal' => $subtotal,
                'total' => $total,
                'notes' => __('messages.sales_order_alternative_note'),
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);

            app(DocumentSerialService::class)->assignToModel(
                $child,
                DocumentSerialService::TYPE_SALES_ORDER,
                'serial_number',
                $child->created_at
            );

            foreach ($itemsPayload as $row) {
                $product = Product::query()->find($row['product_id']);
                SalesOrderItem::create([
                    'sales_order_id' => $child->id,
                    'product_id' => $row['product_id'],
                    'size_id' => $row['size_id'],
                    'size_color_id' => $row['size_color_id'],
                    'product_name' => $product?->nameAr,
                    'quantity' => $row['quantity'],
                    'unit_price' => $row['unit_price'],
                    'line_total' => round($row['quantity'] * $row['unit_price'], 2),
                ]);
            }

            SalesReturn::create([
                'sales_order_id' => $order->id,
                'return_type' => 'alternative',
                'total_amount' => $subtotal,
                'note' => $note,
                'created_by' => $user->id,
            ]);

            $from = $order->status;
            $order->update([
                'status' => SalesOrderStatus::AlternativeReturn->value,
                'updated_by' => $user->id,
            ]);
            $this->logStatus($order, $from, SalesOrderStatus::AlternativeReturn->value, $note ?? 'راجع بديل', $user->id);
            $this->notifications->notifyStatusChange($order->fresh(), $from, SalesOrderStatus::AlternativeReturn->value, $user, $note);

            return $order->fresh();
        });
    }

    /**
     * @param  array<int, int>  $deliverMap
     */
    private function applyDeliveredQuantities(SalesOrder $order, array $deliverMap): float
    {
        $subtotal = 0.0;

        foreach ($deliverMap as $itemId => $qty) {
            /** @var SalesOrderItem $item */
            $item = $order->items->firstWhere('id', $itemId);
            $item->update([
                'delivered_qty' => (int) $item->delivered_qty + $qty,
            ]);
            $subtotal += round($qty * (float) $item->unit_price, 2);
        }

        return $subtotal;
    }

    /**
     * @param  array<int, int>  $deliverMap
     * @return Collection<int, SalesOrderItem>
     */
    private function buildItemSubset(SalesOrder $order, array $deliverMap): Collection
    {
        $subset = collect();

        foreach ($deliverMap as $itemId => $qty) {
            /** @var SalesOrderItem|null $item */
            $item = $order->items->firstWhere('id', $itemId);
            if (! $item) {
                continue;
            }

            $clone = $item->replicate();
            $clone->quantity = $qty;
            $clone->line_total = round($qty * (float) $item->unit_price, 2);
            $subset->push($clone);
        }

        return $subset;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function remainingItems(SalesOrder $order): array
    {
        $rows = [];

        foreach ($order->items as $item) {
            if ($item->is_hidden) {
                continue;
            }

            $remaining = (int) $item->quantity - (int) $item->delivered_qty;
            if ($remaining <= 0) {
                continue;
            }

            $rows[] = [
                'product_id' => $item->product_id,
                'size_id' => $item->size_id,
                'size_color_id' => $item->size_color_id,
                'product_name' => $item->product_name,
                'quantity' => $remaining,
                'unit_price' => (float) $item->unit_price,
            ];
        }

        return $rows;
    }

    private function isFullyDelivered(SalesOrder $order): bool
    {
        foreach ($order->fresh(['items'])->items as $item) {
            if ($item->is_hidden) {
                continue;
            }
            if ((int) $item->delivered_qty < (int) $item->quantity) {
                return false;
            }
        }

        return true;
    }

    private function isFullyReturnedFromCarrier(SalesOrder $order): bool
    {
        foreach ($order->items as $item) {
            if ($item->is_hidden) {
                continue;
            }
            if ((int) $item->dispatched_qty > 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, array{item_id: int, quantity: int}>  $lines
     * @return array<int, int>
     */
    private function parseItemLines(SalesOrder $order, array $lines, string $mode): array
    {
        if ($lines === []) {
            throw ValidationException::withMessages([
                'items' => [__('messages.sales_order_items_required')],
            ]);
        }

        $map = [];

        foreach ($lines as $line) {
            $itemId = (int) ($line['item_id'] ?? 0);
            $qty = (int) ($line['quantity'] ?? 0);

            if ($itemId <= 0 || $qty <= 0) {
                continue;
            }

            /** @var SalesOrderItem|null $item */
            $item = $order->items->firstWhere('id', $itemId);
            if (! $item || $item->is_hidden) {
                throw ValidationException::withMessages([
                    'items' => [__('messages.sales_order_item_not_found')],
                ]);
            }

            if ($mode === 'deliver') {
                $max = (int) $item->quantity - (int) $item->delivered_qty;
            } else {
                $max = (int) $item->dispatched_qty - (int) $item->delivered_qty - (int) $item->returned_qty;
            }

            if ($qty > $max) {
                throw ValidationException::withMessages([
                    'items' => [__('messages.sales_order_qty_exceeded')],
                ]);
            }

            $map[$itemId] = ($map[$itemId] ?? 0) + $qty;
        }

        if ($map === []) {
            throw ValidationException::withMessages([
                'items' => [__('messages.sales_order_items_required')],
            ]);
        }

        return $map;
    }

    private function assertTransition(SalesOrder $order, array $allowed): void
    {
        $current = $order->statusEnum();
        if (! in_array($current, $allowed, true)) {
            throw ValidationException::withMessages([
                'order' => [__('messages.sales_order_invalid_status_transition')],
            ]);
        }
    }

    private function logStatus(
        SalesOrder $order,
        ?string $from,
        string $to,
        ?string $note,
        ?int $userId
    ): void {
        \App\Models\SalesOrderStatusLog::create([
            'sales_order_id' => $order->id,
            'from_status' => $from,
            'to_status' => $to,
            'note' => $note,
            'user_id' => $userId,
        ]);
    }
}
