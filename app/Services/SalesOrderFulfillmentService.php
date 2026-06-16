<?php

namespace App\Services;

use App\Enums\SalesOrderStatus;
use App\Http\Controllers\API\BoxLogs;
use App\Models\Box;
use App\Models\InstantSale;
use App\Models\SalesOrder;
use App\Models\SalesOrderDelivery;
use App\Models\SalesOrderItem;
use App\Models\SalesOrderMedia;
use App\Models\SalesOrderStatusLog;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesOrderFulfillmentService
{
    public const MAX_MEDIA_FILES = 100;

    public const MAX_MEDIA_BYTES = 52428800;

    public function __construct(
        protected SalesOrderStockService $stockService,
        protected SalesDailySessionService $sessionService,
        protected DebtLedgerService $debtLedgerService,
        protected SalesOrderNotificationService $notifications,
    ) {}

    public function handoverToDelivery(User $user, int $orderId, array $payload): SalesOrder
    {
        $order = SalesOrder::query()->with(['items', 'packages'])->findOrFail($orderId);
        $this->assertTransition($order, [SalesOrderStatus::Ready]);

        $data = validator($payload, [
            'delivery_company_id' => 'nullable|integer|exists:delivery_companies,id',
            'delivery_company_name' => 'nullable|string|max:255',
            'tracking_number' => 'nullable|string|max:100',
            'carrier_delivery_cost' => 'nullable|numeric|min:0',
        ])->validate();

        if (empty($data['delivery_company_id']) && empty($data['delivery_company_name'])) {
            throw ValidationException::withMessages([
                'delivery_company_id' => [__('messages.sales_order_delivery_company_required')],
            ]);
        }

        return DB::transaction(function () use ($user, $order, $data) {
            $this->stockService->dispatchOrder($order, (int) $user->id);

            SalesOrderDelivery::create([
                'sales_order_id' => $order->id,
                'delivery_company_id' => $data['delivery_company_id'] ?? null,
                'delivery_company_name' => $data['delivery_company_name'] ?? null,
                'tracking_number' => $data['tracking_number'] ?? null,
                'handed_over_at' => now(),
            ]);

            $order->packages()->update(['status' => 'with_delivery']);

            $from = $order->status;
            $order->update([
                'status' => SalesOrderStatus::WithDelivery->value,
                'delivery_company_id' => $data['delivery_company_id'] ?? $order->delivery_company_id,
                'delivery_company_name' => $data['delivery_company_name'] ?? $order->delivery_company_name,
                'carrier_delivery_cost' => $data['carrier_delivery_cost'] ?? $order->carrier_delivery_cost,
                'stock_deducted_at' => now(),
                'updated_by' => $user->id,
            ]);

            if (! empty($data['tracking_number'])) {
                $order->packages()->update(['tracking_number' => $data['tracking_number']]);
            }

            $this->logStatus($order, $from, SalesOrderStatus::WithDelivery->value, 'تسليم لشركة التوصيل', $user->id);
            $this->notifications->notifyStatusChange($order->fresh(), $from, SalesOrderStatus::WithDelivery->value, $user);

            return $order->fresh();
        });
    }

    public function markDelivered(User $user, int $orderId, array $payload = []): SalesOrder
    {
        $order = SalesOrder::query()->with(['items'])->findOrFail($orderId);
        $this->assertTransition($order, [SalesOrderStatus::WithDelivery]);

        $data = validator($payload, [
            'payment_amount' => 'nullable|numeric|min:0',
            'payment_box_id' => 'nullable|integer|exists:boxes,id',
        ])->validate();

        if (! empty($data['payment_amount'])) {
            $order->payment_amount = (float) $data['payment_amount'];
        }

        return DB::transaction(function () use ($user, $order, $data) {
            $session = $this->sessionService->assertCanCreateSale($user);
            $instantSale = $this->createInstantSaleFromOrder($order, $user, $session, $data);

            $order->items()->where('is_hidden', false)->update([
                'delivered_qty' => DB::raw('quantity'),
            ]);

            SalesOrderDelivery::query()
                ->where('sales_order_id', $order->id)
                ->latest('id')
                ->limit(1)
                ->update(['delivered_at' => now()]);

            $order->packages()->update(['status' => 'delivered']);

            $from = $order->status;
            $order->update([
                'status' => SalesOrderStatus::Delivered->value,
                'instant_sale_id' => $instantSale->id,
                'financial_posted_at' => now(),
                'payment_box_id' => $instantSale->payment_box_id,
                'payment_amount' => (float) ($instantSale->payment_box_value ?? 0),
                'updated_by' => $user->id,
            ]);

            $this->logStatus($order, $from, SalesOrderStatus::Delivered->value, 'تم التوصيل', $user->id);
            $this->notifications->notifyStatusChange($order->fresh(), $from, SalesOrderStatus::Delivered->value, $user);

            return $order->fresh();
        });
    }

    public function settleDelivery(User $user, int $orderId, array $payload): SalesOrder
    {
        $order = SalesOrder::query()->findOrFail($orderId);
        $this->assertTransition($order, [SalesOrderStatus::Delivered]);

        $data = validator($payload, [
            'delivery_settled_amount' => 'required|numeric|min:0',
        ])->validate();

        return DB::transaction(function () use ($user, $order, $data) {
            $order->update([
                'delivery_settled_at' => now(),
                'delivery_settled_amount' => (float) $data['delivery_settled_amount'],
                'updated_by' => $user->id,
            ]);

            $this->logStatus(
                $order,
                $order->status,
                SalesOrderStatus::Delivered->value,
                'تسوية مع شركة التوصيل',
                $user->id
            );

            return $order->fresh();
        });
    }

    public function archive(User $user, int $orderId): SalesOrder
    {
        $order = SalesOrder::query()->findOrFail($orderId);
        $this->assertTransition($order, [SalesOrderStatus::Delivered]);

        if (! $order->delivery_settled_at) {
            throw ValidationException::withMessages([
                'order' => [__('messages.sales_order_settlement_required')],
            ]);
        }

        return DB::transaction(function () use ($user, $order) {
            $from = $order->status;
            $order->update([
                'status' => SalesOrderStatus::Archived->value,
                'archived_at' => now(),
                'updated_by' => $user->id,
            ]);
            $this->logStatus($order, $from, SalesOrderStatus::Archived->value, 'أرشفة الطلبية', $user->id);
            $this->notifications->notifyStatusChange($order->fresh(), $from, SalesOrderStatus::Archived->value, $user);

            return $order->fresh();
        });
    }

    public function markReturned(User $user, int $orderId, ?string $note = null): SalesOrder
    {
        $order = SalesOrder::query()->findOrFail($orderId);
        $this->assertTransition($order, [SalesOrderStatus::WithDelivery]);

        return DB::transaction(function () use ($user, $order, $note) {
            if ($order->stock_deducted_at) {
                $this->stockService->restoreDispatchedOrder($order, (int) $user->id);
            } else {
                $this->stockService->releaseOrder($order);
            }

            $from = $order->status;
            $order->update([
                'status' => SalesOrderStatus::Returned->value,
                'updated_by' => $user->id,
            ]);
            $order->packages()->update(['status' => 'returned']);

            $this->logStatus($order, $from, SalesOrderStatus::Returned->value, $note ?? 'راجع من شركة التوصيل', $user->id);
            $this->notifications->notifyStatusChange($order->fresh(), $from, SalesOrderStatus::Returned->value, $user, $note);

            return $order->fresh();
        });
    }

    public function uploadMedia(User $user, int $orderId, array $files): SalesOrder
    {
        $order = SalesOrder::query()->findOrFail($orderId);

        if ($order->statusEnum() === SalesOrderStatus::Archived) {
            throw ValidationException::withMessages([
                'order' => [__('messages.sales_order_not_editable')],
            ]);
        }

        $existingCount = SalesOrderMedia::query()->where('sales_order_id', $order->id)->count();
        if ($existingCount + count($files) > self::MAX_MEDIA_FILES) {
            throw ValidationException::withMessages([
                'media' => [__('messages.sales_order_media_limit', ['max' => self::MAX_MEDIA_FILES])],
            ]);
        }

        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }
            if ($file->getSize() > self::MAX_MEDIA_BYTES) {
                throw ValidationException::withMessages([
                    'media' => [__('messages.sales_order_media_size_limit')],
                ]);
            }
        }

        DB::transaction(function () use ($user, $order, $files) {
            foreach ($files as $file) {
                if (! $file instanceof UploadedFile) {
                    continue;
                }

                $path = $file->store('sales_orders/'.$order->id, 'public');
                $mime = $file->getMimeType() ?? '';
                $type = str_starts_with($mime, 'video/') ? 'video' : 'image';

                SalesOrderMedia::create([
                    'sales_order_id' => $order->id,
                    'status_at_upload' => $order->status,
                    'type' => $type,
                    'path' => $path,
                    'mime' => $mime,
                    'size_bytes' => $file->getSize(),
                    'uploaded_by' => $user->id,
                ]);
            }
        });

        return $order->fresh();
    }

    /**
     * @param  Collection<int, SalesOrderItem>  $items
     */
    public function createInstantSaleForItems(
        SalesOrder $order,
        Collection $items,
        User $user,
        float $orderTotal,
        float $deliveryFee,
        float $discount,
        array $payload = []
    ): InstantSale {
        if ($items->isEmpty()) {
            throw ValidationException::withMessages([
                'items' => [__('messages.sales_order_requires_items')],
            ]);
        }

        $session = $this->sessionService->assertCanCreateSale($user);
        $paidAmount = $this->resolvePaidAmountForTotal($order, $orderTotal, $payload);
        $paymentBox = $this->resolvePaymentBox($user, $paidAmount, $payload);

        if ($paidAmount > 0 && $paymentBox) {
            $box = Box::lockForUpdate()->findOrFail($paymentBox['id']);
            $box->total = round((float) $box->total + $paidAmount, 2);
            $box->save();

            BoxLogs::createBoxLog(
                $box,
                'قبض — طلبية '.$order->serial_number,
                'add',
                $paidAmount,
                'طلبية #'.$order->id.' — '.$order->serial_number
            );
        }

        $first = $items->first();
        $buyer = [
            'buyer_type' => $order->customer_id ? 'customer' : 'unknown',
            'buyer_id' => $order->customer_id,
            'buyer_name' => $order->customer_name ?? '-',
            'buyer_phone' => $order->customer_phone,
            'buyer_address' => $order->customer_address,
        ];

        $notes = trim('طلبية '.($order->serial_number ?? '#'.$order->id)
            .($deliveryFee > 0 ? ' | توصيل: '.$deliveryFee : ''));

        $main = InstantSale::create(array_merge($buyer, [
            'product_id' => $first->product_id,
            'size_id' => $first->size_id,
            'size_color_id' => $first->size_color_id,
            'quantity' => $first->quantity,
            'cost' => $first->unit_price,
            'discount' => $discount,
            'total_cost' => $orderTotal,
            'notes' => $notes,
            'type' => 'normal',
            'payment_box_id' => $paymentBox['id'] ?? null,
            'payment_box_name' => $paymentBox['name'] ?? null,
            'payment_box_value' => $paidAmount,
            'sales_daily_session_id' => $session->id,
            'created_by' => $user->id,
            'status' => 'active',
        ]));

        app(DocumentSerialService::class)->assignToModel(
            $main,
            DocumentSerialService::TYPE_INSTANT_SALE_INVOICE,
            'serial_number'
        );

        foreach ($items->slice(1) as $item) {
            InstantSale::create(array_merge($buyer, [
                'product_id' => $item->product_id,
                'size_id' => $item->size_id,
                'size_color_id' => $item->size_color_id,
                'quantity' => $item->quantity,
                'cost' => $item->unit_price,
                'discount' => 0,
                'total_cost' => $item->line_total,
                'parent_id' => $main->id,
                'type' => 'normal',
                'sales_daily_session_id' => $session->id,
                'created_by' => $user->id,
                'status' => 'active',
            ]));
        }

        $this->debtLedgerService->syncInstantSaleToLedger(
            $main->fresh(['product', 'offerPackage', 'paymentBox'])
        );

        return $main;
    }

    public function assertHasRequiredMedia(SalesOrder $order): void
    {
        // Media is optional — user may upload any number of photos/videos when needed.
    }

    private function createInstantSaleFromOrder(
        SalesOrder $order,
        User $user,
        $session,
        array $payload = []
    ): InstantSale {
        if ($order->instant_sale_id) {
            return InstantSale::query()->findOrFail($order->instant_sale_id);
        }

        $order->loadMissing('items');
        $items = $order->items->where('is_hidden', false)->values();

        if ($items->isEmpty()) {
            throw ValidationException::withMessages([
                'items' => [__('messages.sales_order_requires_items')],
            ]);
        }

        return $this->createInstantSaleForItems(
            $order,
            $items,
            $user,
            (float) $order->total,
            (float) $order->customer_delivery_fee,
            (float) $order->discount,
            $payload
        );
    }

    private function resolvePaidAmountForTotal(SalesOrder $order, float $total, array $payload): float
    {
        if (isset($payload['payment_amount'])) {
            return min((float) $payload['payment_amount'], $total);
        }

        return match ($order->payment_type) {
            'credit' => 0.0,
            'mixed' => min((float) $order->payment_amount, $total),
            default => $total,
        };
    }

    /**
     * @return array{id: int, name: string}|null
     */
    private function resolvePaymentBox(User $user, float $paidAmount, array $payload): ?array
    {
        if ($paidAmount <= 0) {
            return null;
        }

        if (! empty($payload['payment_box_id'])) {
            $box = Box::query()->find($payload['payment_box_id']);
            if ($box) {
                return ['id' => (int) $box->id, 'name' => (string) $box->name];
            }
        }

        $dailyBox = $this->sessionService->ensureDailyBoxes($user)->first();

        return $dailyBox
            ? ['id' => (int) $dailyBox->id, 'name' => (string) $dailyBox->name]
            : null;
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
        SalesOrderStatusLog::create([
            'sales_order_id' => $order->id,
            'from_status' => $from,
            'to_status' => $to,
            'note' => $note,
            'user_id' => $userId,
        ]);
    }
}
