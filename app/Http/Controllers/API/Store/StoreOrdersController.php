<?php

namespace App\Http\Controllers\API\Store;

use App\Models\Store\StoreSalesOrder;
use App\Models\Store\StoreSalesOrderItem;
use App\Models\User;
use App\Services\AdminNotificationService;
use App\Services\EmployeeActivityLogger;
use App\Services\OnlineStore\OnlineStoreCheckoutService;
use App\Services\OnlineStore\StoreIdentityService;
use App\Services\SalesOrderService;
use Illuminate\Http\Request;

class StoreOrdersController extends StoreBaseController
{
    public function checkout(Request $request, OnlineStoreCheckoutService $checkout)
    {
        $actor = $this->authenticatedUser($request);
        $data = $request->validate([
            'client_request_id' => 'required|string|max:100', 'account_role' => 'required|in:customer,seller',
            'payment' => 'required|array', 'payment.type' => 'required|in:cash', 'payment.paid_amount' => 'nullable|numeric|min:0',
            'coupon_code' => 'nullable|string|max:100', 'delivery' => 'nullable|array',
            'delivery.customer_address' => 'nullable|string|max:1000', 'delivery.city_id' => 'nullable|integer',
            'delivery.shiply_city_id' => 'nullable|integer', 'delivery.shiply_village_id' => 'nullable|integer',
            'delivery.partner_address_id' => 'nullable|integer', 'delivery.delivery_company_id' => 'nullable|integer',
            'items' => 'required|array|min:1', 'items.*.listing_id' => 'required|integer',
            'items.*.size_id' => 'nullable|integer', 'items.*.size_color_id' => 'nullable|integer',
            'items.*.quantity' => 'required|integer|min:1',
        ]);
        $data['payment_type'] = $data['payment']['type'];
        foreach (['customer_address', 'city_id', 'shiply_city_id', 'shiply_village_id', 'partner_address_id', 'delivery_company_id'] as $field) {
            if (array_key_exists($field, $data['delivery'] ?? [])) {
                $data[$field] = $data['delivery'][$field];
            }
        }
        $result = $checkout->checkout($actor, $data);

        return response()->json(['data' => $result['order']->fresh(['items', 'statusLogs', 'couponRedemption']), 'replayed' => ! $result['created']], $result['created'] ? 201 : 200);
    }

    public function manageOrder(Request $request, OnlineStoreCheckoutService $checkout)
    {
        $actor = $this->authenticatedUser($request);
        if (collect($request->input('details', []))->isEmpty()) {
            return response()->json(['message' => 'OrderDetailsRequired'], 400);
        }
        $result = $checkout->legacyCheckout($actor, $request->all());
        $order = StoreSalesOrder::query()->with($this->orderRelations())->findOrFail($result['order']->getKey());
        if ($result['created']) {
            app(AdminNotificationService::class)->notifyStoreOrderCreated($order);
            app(EmployeeActivityLogger::class)->logForUserId((int) $actor->getKey(), 'sales', 'created_sales_order', 'إنشاء طلبية مبيعات',
                'تم إنشاء طلبية رقم '.($order->serial_number ?? $order->id).' بقيمة '.number_format((float) $order->total, 2, '.', ''), $order, (float) $order->total,
                ['order_number' => $order->serial_number, 'customer_name' => $order->customer_name, 'customer_phone' => $order->customer_phone, 'items_count' => $order->details->count()]);
        }

        return response()->json($this->orderPayload($order));
    }

    public function getAllOrdersByUserId(Request $request, StoreIdentityService $identity)
    {
        $actor = $this->authenticatedUser($request);
        $this->rejectForeignSubmittedUser($request, $actor);
        $status = $request->query('statusOrder', $request->input('statusOrder'));

        $ownedIds = $identity->ownedOrders($actor)->pluck('id');
        $query = StoreSalesOrder::query()->whereIn('id', $ownedIds)
            ->with($this->orderRelations())
            ->orderByDesc('id');

        if ($status !== null && $status !== '') {
            $query->where('status', $this->toSalesOrderStatus($status));
        }

        $rows = $query->limit(200)->get()->map(fn (StoreSalesOrder $order) => $this->orderPayload($order, ['customerId' => $actor->id]));

        return response()->json($this->rowsResponse($rows));
    }

    public function cancelOrder(Request $request, StoreIdentityService $identity, SalesOrderService $orders)
    {
        $actor = $this->authenticatedUser($request);
        $this->rejectForeignSubmittedUser($request, $actor);
        $orderId = $request->query('id', $request->input('id', $request->input('orderId')));
        if (! is_numeric($orderId)) {
            return response()->json(['message' => 'OrderIdRequired'], 400);
        }

        $owned = $identity->ownedOrderOrFail($actor, (int) $orderId);
        $fromStatus = $owned->status;
        $orders->cancel($actor, (int) $owned->getKey(), 'Store customer canceled the order');
        $order = StoreSalesOrder::query()->with($this->orderRelations())->findOrFail($owned->getKey());
        app(AdminNotificationService::class)->notifyStoreOrderCanceled($order);
        app(EmployeeActivityLogger::class)->logForUserId(
            (int) $actor->getKey(),
            'sales',
            'canceled_sales_order',
            'إلغاء طلبية مبيعات',
            'تم إلغاء طلبية رقم '.($order->serial_number ?? $order->id),
            $order,
            (float) ($order->total ?? 0),
            [
                'order_number' => $order->serial_number,
                'from_status' => $fromStatus,
                'to_status' => 'canceled',
            ]
        );

        return response()->json($this->orderPayload($order->fresh($this->orderRelations())));
    }

    private function authenticatedUser(Request $request): User
    {
        $storeUser = $this->storeUserFromRequest($request);
        if (! $storeUser) {
            abort(401, 'Unauthenticated.');
        }
        $user = User::query()->find($storeUser->getKey());
        if (! $user || $user->is_blocked) {
            abort(401, 'Unauthenticated.');
        }

        return $user;
    }

    private function rejectForeignSubmittedUser(Request $request, User $actor): void
    {
        $submitted = $request->query('userId', $request->input('userId', $request->input('userUpdate')));
        if (is_numeric($submitted) && (int) $submitted !== (int) $actor->getKey()) {
            abort(404);
        }
    }

    private function orderPayload(StoreSalesOrder $order, array $fallback = []): array
    {
        $cityId = $order->shiply_city_id ? (int) $order->shiply_city_id : (int) ($fallback['cityId'] ?? 0);
        $subtotal = (float) ($order->subtotal ?? 0);
        $discount = (float) ($order->discount ?? 0);
        $redemption = $order->couponRedemption;

        return [
            'id' => (int) $order->id,
            'serialNumber' => (string) ($order->serial_number ?? ''),
            'orderNumber' => (string) ($order->serial_number ?? $order->id),
            'customerId' => (string) ($fallback['customerId'] ?? $order->origin_user_id ?? $order->created_by ?? ''),
            'customerName' => (string) ($order->customer_name ?? $fallback['customerName'] ?? ''),
            'phoneNum1' => (string) ($order->customer_phone ?? $fallback['phoneNum1'] ?? ''),
            'phoneNum2' => '',
            'cityId' => $cityId,
            'address' => (string) ($order->customer_address ?? $fallback['address'] ?? ''),
            'status' => $this->fromSalesOrderStatus($order->status),
            'isWholesale' => $order->partner_type === 'seller',
            'priceDelivery' => (float) ($order->customer_delivery_fee ?? 0),
            'totalPriceWithDiscound' => max(0, $subtotal - $discount),
            'totalPriceWithOutDiscound' => $subtotal,
            'discoundCodeId' => $redemption?->coupon_id,
            'discoundCodePercent' => $redemption?->coupon?->discount_type === 'percentage' ? (float) $redemption->coupon->discount_value : null,
            'discoundCode' => $redemption?->coupon?->code,
            'totalPriceWithDiscoundCode' => $redemption ? max(0, $subtotal - $discount) : null,
            'userAddId' => (string) ($order->origin_user_id ?? $order->created_by ?? ''),
            'dateAdd' => $this->dateString($order->created_at),
            'userUpdate' => (string) ($order->updated_by ?? $fallback['userUpdate'] ?? ''),
            'dateUpdate' => $this->dateString($order->updated_at),
            'latestHandover' => $this->handoverPayload($order->latestHandover),
            'statusLogs' => $order->statusLogs
                ->sortByDesc('created_at')
                ->map(fn ($log) => [
                    'fromStatus' => $this->fromSalesOrderStatus($log->from_status),
                    'toStatus' => $this->fromSalesOrderStatus($log->to_status),
                    'note' => (string) ($log->note ?? ''),
                    'userName' => (string) ($log->user?->name ?? ''),
                    'createdAt' => $this->dateString($log->created_at),
                ])
                ->values(),
            'shiplyTracking' => $this->shiplyTrackingPayload($order),
            'details' => $order->details->map(function (StoreSalesOrderItem $detail) {
                $product = $detail->product;

                return [
                    'id' => (int) $detail->id,
                    'orderId' => (int) $detail->sales_order_id,
                    'itemId' => (int) $detail->product_id,
                    'isOrderSize' => $detail->size_id !== null || $detail->size_color_id !== null,
                    'itemSizeColorId' => $detail->size_color_id ? (int) $detail->size_color_id : null,
                    'itemSizeId' => $detail->size_id ? (int) $detail->size_id : null,
                    'quantity' => (int) $detail->quantity,
                    'itemPrice' => (float) $detail->unit_price,
                    'totalPriceWithDiscound' => (float) $detail->line_total,
                    'totalPriceWithOutDiscound' => (float) $detail->unit_price * (int) $detail->quantity,
                    'itemSizeColor' => null,
                    'itemSize' => null,
                    'item' => $product ? $this->productPayload($product) : null,
                ];
            })->values(),
        ];
    }

    private function orderRelations(): array
    {
        return [
            'details.product.subCategories',
            'details.product.normalImages',
            'details.product.viewImages',
            'details.product.image3d',
            'details.product.sizes.colors',
            'latestHandover',
            'statusLogs.user',
            'shiplyEvents',
            'couponRedemption.coupon',
        ];
    }

    private function handoverPayload($handover): ?array
    {
        if (! $handover) {
            return null;
        }

        return [
            'id' => (int) $handover->id,
            'deliveryCompanyName' => (string) ($handover->delivery_company_name ?? ''),
            'deliveryCompanyCode' => (string) ($handover->delivery_company_code ?? ''),
            'trackingNumber' => (string) ($handover->tracking_number ?? ''),
            'carrierContactName' => (string) ($handover->carrier_contact_name ?? ''),
            'carrierContactPhone' => (string) ($handover->carrier_contact_phone ?? ''),
            'carrierOfficeName' => (string) ($handover->carrier_office_name ?? ''),
            'carrierVehicleNumber' => (string) ($handover->carrier_vehicle_number ?? ''),
            'shiplyParcelCode' => (string) ($handover->shiply_parcel_code ?? ''),
            'handedOverAt' => $this->dateString($handover->handed_over_at),
            'deliveredAt' => $this->dateString($handover->delivered_at),
        ];
    }

    private function shiplyTrackingPayload(StoreSalesOrder $order): ?array
    {
        $events = $order->shiplyEvents->sortBy('occurred_at')->values();
        $parcelCode = (string) ($order->latestHandover?->shiply_parcel_code ?? $events->last()?->parcel_code ?? '');

        if ($parcelCode === '' && $events->isEmpty()) {
            return null;
        }

        $currentEvent = $events->last();
        $currentStatusId = (int) ($currentEvent?->parcel_status_id ?? 0);

        return [
            'parcelCode' => $parcelCode,
            'shiplyMode' => (string) ($order->latestHandover?->shiply_mode ?? $currentEvent?->shiply_mode ?? ''),
            'currentStatusId' => $currentStatusId,
            'currentStatusKey' => $this->shiplyStatusKey($currentStatusId),
            'currentStatusLabel' => $this->shiplyStatusLabel($currentStatusId),
            'statusSequence' => [1, 2, 3, 4, 5, 6, 7],
            'events' => $events->map(fn ($event) => [
                'id' => (int) $event->id,
                'parcelStatusId' => (int) $event->parcel_status_id,
                'statusKey' => $this->shiplyStatusKey((int) $event->parcel_status_id),
                'statusLabel' => $this->shiplyStatusLabel((int) $event->parcel_status_id),
                'note' => (string) ($event->note ?? ''),
                'source' => (string) ($event->source ?? ''),
                'occurredAt' => $this->dateString($event->occurred_at),
            ])->values(),
        ];
    }

    private function toSalesOrderStatus($status): string
    {
        return match (strtolower((string) $status)) {
            'done', 'completed', 'complete', 'delivered' => 'delivered',
            'canceled', 'cancelled', 'cancel', 'ملغي' => 'canceled',
            default => 'unconfirmed',
        };
    }

    private function fromSalesOrderStatus(?string $status): string
    {
        return match ($status) {
            'delivered' => 'Done',
            'canceled' => 'Canceled',
            default => 'New',
        };
    }

    private function shiplyStatusKey(int $statusId): string
    {
        return match ($statusId) {
            1 => 'draft',
            2 => 'submitted',
            3 => 'on_the_way',
            4 => 'attempt_to_deliver',
            5 => 'pending',
            6 => 'delivered',
            7 => 'returned',
            default => 'pending',
        };
    }

    private function shiplyStatusLabel(int $statusId): string
    {
        return match ($statusId) {
            1 => 'Draft',
            2 => 'Submitted to Shiply',
            3 => 'On the way',
            4 => 'Delivery attempt',
            5 => 'Pending',
            6 => 'Delivered',
            7 => 'Returned',
            default => 'Pending',
        };
    }
}
