<?php

namespace App\Services;

use App\Models\SalesOrder;
use App\Models\SalesOrderStockShortage;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class SalesOrderStockShortageService
{
    public function __construct(
        private AdminNotificationService $admins,
        private EmployeeNotificationService $employees,
    ) {}

    /** @param list<array<string, mixed>> $conflicts */
    public function syncAndNotify(SalesOrder $order, array $conflicts, User $actor): void
    {
        $activeKeys = [];

        foreach ($conflicts as $conflict) {
            $productId = (int) $conflict['product_id'];
            $variantId = ! empty($conflict['size_color_id']) ? (int) $conflict['size_color_id'] : null;
            $activeKeys[] = $productId.'_'.($variantId ?? 0);
            $item = $order->items->first(fn ($row) => (int) $row->product_id === $productId
                && (int) ($row->size_color_id ?? 0) === (int) ($variantId ?? 0));
            $existing = SalesOrderStockShortage::query()
                ->where('sales_order_id', $order->id)
                ->where('product_id', $productId)
                ->where(fn ($q) => $variantId ? $q->where('size_color_id', $variantId) : $q->whereNull('size_color_id'))
                ->first();
            $deficit = (int) $conflict['deficit'];
            $shouldNotify = ! $existing || $deficit > (int) $existing->shortage_qty || ! $existing->last_notified_at;

            $shortage = $existing ?? new SalesOrderStockShortage();
            $shortage->fill([
                'sales_order_id' => $order->id,
                'sales_order_item_id' => $item?->id,
                'product_id' => $productId,
                'size_color_id' => $variantId,
                'requested_qty' => (int) $conflict['requested_qty'],
                'available_qty' => (int) $conflict['available'],
                'shortage_qty' => $deficit,
                'status' => 'open',
                'resolved_at' => null,
                'resolved_by' => null,
            ]);
            if ($shouldNotify) {
                $shortage->last_notified_at = now();
            }
            $shortage->save();

            if ($shouldNotify) {
                $this->notify($order, $conflict, $actor);
            }
        }

        $order->stockShortages()->where('status', 'open')->get()->each(function ($row) use ($activeKeys, $actor) {
            $key = $row->product_id.'_'.($row->size_color_id ?? 0);
            if (! in_array($key, $activeKeys, true)) {
                $row->update(['status' => 'resolved', 'resolved_at' => now(), 'resolved_by' => $actor->id]);
            }
        });
    }

    private function notify(SalesOrder $order, array $conflict, User $actor): void
    {
        try {
            $serial = $order->serial_number ?? '#'.$order->id;
            $product = $conflict['product_name'] ?? 'منتج';
            $body = "الطلبية {$serial}: {$product}، المطلوب {$conflict['requested_qty']}، المتاح {$conflict['available']}، النقص {$conflict['deficit']}. يرجى توفير الكمية.";
            $data = [
                'sales_order_id' => (string) $order->id,
                'serial_number' => (string) $serial,
                'product_id' => (string) $conflict['product_id'],
                'size_color_id' => (string) ($conflict['size_color_id'] ?? ''),
                'requested_qty' => (string) $conflict['requested_qty'],
                'available_qty' => (string) $conflict['available'],
                'shortage_qty' => (string) $conflict['deficit'],
            ];
            $this->admins->create(AdminNotificationService::TYPE_NEGATIVE_SALES_ORDER_STOCK,
                'طلبية تحتاج توفير مخزون', $body, $data, $actor->employee?->id,
                SalesOrder::class, (int) $order->id);
            if ($actor->employee) {
                $this->employees->create($actor->employee,
                    EmployeeNotificationService::TYPE_NEGATIVE_SALES_ORDER_STOCK,
                    'الطلبية تحتاج توفير مخزون', $body, $data, SalesOrder::class, (int) $order->id);
            }
        } catch (\Throwable $e) {
            Log::error('Sales order shortage notification failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
        }
    }
}
