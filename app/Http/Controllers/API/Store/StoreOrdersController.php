<?php

namespace App\Http\Controllers\API\Store;

use App\Models\City;
use App\Models\Store\StoreProduct;
use App\Models\Store\StoreSalesOrder;
use App\Models\Store\StoreSalesOrderItem;
use App\Models\Store\StoreShiplyCity;
use App\Models\Store\StoreShiplyVillage;
use App\Models\SalesOrderStatusLog;
use App\Services\AdminNotificationService;
use App\Services\DocumentSerialService;
use App\Services\ShiplyService;
use App\Support\ShiplySettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StoreOrdersController extends StoreBaseController
{
    public function manageOrder(Request $request)
    {
        $details = collect($request->input('details', []));
        if ($details->isEmpty()) {
            return response()->json(['message' => 'OrderDetailsRequired'], 400);
        }

        $cityId = $request->input('cityId');
        $mode = ShiplySettings::mode();
        $shiplyCity = is_numeric($cityId)
            ? StoreShiplyCity::query()
                ->where('mode', $mode)
                ->where('shiply_id', (int) $cityId)
                ->whereNull('deleted_at_remote')
                ->first()
            : null;
        $shiplyVillage = $this->resolveVillageForCity(
            $shiplyCity,
            $mode,
            $request->input('shiplyVillageId', $request->input('villageId'))
        );

        $order = DB::transaction(function () use ($request, $details, $cityId, $shiplyCity, $shiplyVillage, $mode) {
            $subtotal = (float) $request->input('totalPriceWithOutDiscound', 0);
            $discounted = (float) $request->input('totalPriceWithDiscound', $subtotal);
            $couponTotal = $request->input('totalPriceWithDiscoundCode');
            $totalBeforeDelivery = $couponTotal !== null ? (float) $couponTotal : $discounted;
            $delivery = $this->deliveryFeeForShiplyCity($shiplyCity, $shiplyVillage, $totalBeforeDelivery, $mode);
            $total = $totalBeforeDelivery + $delivery;

            $order = StoreSalesOrder::query()->create([
                'serial_number' => null,
                'customer_id' => null,
                'customer_name' => $request->input('customerName'),
                'customer_phone' => $request->input('phoneNum1'),
                'customer_address' => $request->input('address'),
                'city_id' => null,
                'shiply_city_id' => is_numeric($cityId) ? (int) $cityId : null,
                'shiply_village_id' => $shiplyVillage?->shiply_id,
                'shiply_city_name' => $shiplyCity?->name,
                'shiply_village_name' => $shiplyVillage?->name,
                'status' => $this->toSalesOrderStatus($request->input('status', 'New')),
                'payment_type' => 'cash',
                'customer_delivery_fee' => $delivery,
                'subtotal' => $subtotal,
                'discount' => max(0, $subtotal - $discounted),
                'total' => $total,
                'notes' => $request->filled('discoundCode')
                    ? 'Store discount code: '.$request->input('discoundCode')
                    : null,
                'created_by' => is_numeric($request->input('userAddId')) ? (int) $request->input('userAddId') : null,
                'updated_by' => is_numeric($request->input('userUpdate')) ? (int) $request->input('userUpdate') : null,
            ]);

            foreach ($details as $detail) {
                $productId = (int) ($detail['itemId'] ?? 0);
                $product = StoreProduct::query()->find($productId);

                StoreSalesOrderItem::query()->create([
                    'sales_order_id' => $order->id,
                    'product_id' => $productId,
                    'size_id' => $this->nullableInt($detail['itemSizeId'] ?? null),
                    'size_color_id' => $this->nullableInt($detail['itemSizeColorId'] ?? null),
                    'product_name' => $product?->nameAr,
                    'quantity' => (int) ($detail['quantity'] ?? 1),
                    'reserved_qty' => 0,
                    'dispatched_qty' => 0,
                    'unit_price' => (float) ($detail['itemPrice'] ?? 0),
                    'line_total' => (float) ($detail['totalPriceWithDiscound'] ?? 0),
                    'is_hidden' => false,
                ]);
            }

            if (empty($order->root_order_id)) {
                $order->forceFill(['root_order_id' => $order->id])->save();
            }

            if (empty($order->serial_number)) {
                $serial = app(DocumentSerialService::class)->nextSerial(
                    DocumentSerialService::TYPE_SALES_ORDER,
                    $order->created_at
                );
                $order->forceFill(['serial_number' => 'S'.$serial])->save();
            }

            SalesOrderStatusLog::query()->create([
                'sales_order_id' => $order->id,
                'from_status' => null,
                'to_status' => $order->status,
                'note' => 'Store order created',
                'user_id' => is_numeric($request->input('userAddId')) ? (int) $request->input('userAddId') : null,
            ]);

            return $order->fresh($this->orderRelations());
        });

        app(AdminNotificationService::class)->notifyStoreOrderCreated($order);

        return response()->json($this->orderPayload($order, $request->all()));
    }

    public function getAllOrdersByUserId(Request $request)
    {
        $userId = $request->query('userId', $request->input('userId'));
        $status = $request->query('statusOrder', $request->input('statusOrder'));

        $query = StoreSalesOrder::query()
            ->with($this->orderRelations())
            ->orderByDesc('id');

        if (is_numeric($userId)) {
            $query->where('created_by', (int) $userId);
        }

        if ($status !== null && $status !== '') {
            $query->where('status', $this->toSalesOrderStatus($status));
        }

        $rows = $query->limit(200)->get()->map(fn (StoreSalesOrder $order) => $this->orderPayload($order));

        return response()->json($this->rowsResponse($rows));
    }

    public function cancelOrder(Request $request)
    {
        $orderId = $request->query('id', $request->input('id', $request->input('orderId')));
        $userId = $request->query('userId', $request->input('userId', $request->input('userUpdate')));

        if (! is_numeric($orderId)) {
            return response()->json(['message' => 'OrderIdRequired'], 400);
        }

        $query = StoreSalesOrder::query()
            ->with($this->orderRelations())
            ->where('id', (int) $orderId);

        if (is_numeric($userId)) {
            $query->where('created_by', (int) $userId);
        }

        $order = $query->first();

        if (! $order) {
            return response()->json(['message' => 'OrderNotFound'], 404);
        }

        $fromStatus = $order->status;

        $order->forceFill([
            'status' => 'canceled',
            'updated_by' => is_numeric($userId) ? (int) $userId : $order->updated_by,
        ])->save();

        SalesOrderStatusLog::query()->create([
            'sales_order_id' => $order->id,
            'from_status' => $fromStatus,
            'to_status' => 'canceled',
            'note' => 'Store customer canceled the order',
            'user_id' => is_numeric($userId) ? (int) $userId : null,
        ]);

        app(AdminNotificationService::class)->notifyStoreOrderCanceled($order);

        return response()->json($this->orderPayload($order->fresh($this->orderRelations())));
    }

    private function orderPayload(StoreSalesOrder $order, array $fallback = []): array
    {
        $cityId = $order->shiply_city_id ? (int) $order->shiply_city_id : (int) ($fallback['cityId'] ?? 0);
        $subtotal = (float) ($order->subtotal ?? 0);
        $discount = (float) ($order->discount ?? 0);

        return [
            'id' => (int) $order->id,
            'serialNumber' => (string) ($order->serial_number ?? ''),
            'orderNumber' => (string) ($order->serial_number ?? $order->id),
            'customerId' => (string) ($fallback['customerId'] ?? $order->created_by ?? ''),
            'customerName' => (string) ($order->customer_name ?? $fallback['customerName'] ?? ''),
            'phoneNum1' => (string) ($order->customer_phone ?? $fallback['phoneNum1'] ?? ''),
            'phoneNum2' => (string) ($fallback['phoneNum2'] ?? ''),
            'cityId' => $cityId,
            'address' => (string) ($order->customer_address ?? $fallback['address'] ?? ''),
            'status' => $this->fromSalesOrderStatus($order->status),
            'isWholesale' => (bool) ($fallback['isWholesale'] ?? false),
            'priceDelivery' => (float) ($order->customer_delivery_fee ?? 0),
            'totalPriceWithDiscound' => max(0, $subtotal - $discount),
            'totalPriceWithOutDiscound' => $subtotal,
            'discoundCodeId' => $fallback['discoundCodeId'] ?? null,
            'discoundCodePercent' => $fallback['discoundCodePercent'] ?? null,
            'discoundCode' => $fallback['discoundCode'] ?? null,
            'totalPriceWithDiscoundCode' => $fallback['totalPriceWithDiscoundCode'] ?? null,
            'userAddId' => (string) ($order->created_by ?? $fallback['userAddId'] ?? ''),
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

    private function nullableInt($value): ?int
    {
        if ($value === null || $value === '' || $value === 'null') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    private function resolveVillageForCity(?StoreShiplyCity $shiplyCity, string $mode, $villageId): ?StoreShiplyVillage
    {
        if (! $shiplyCity) {
            return null;
        }

        $query = StoreShiplyVillage::query()
            ->where('mode', $mode)
            ->where('shiply_city_id', (int) $shiplyCity->shiply_id)
            ->whereNull('deleted_at_remote')
            ->where('is_closed', false);

        if (is_numeric($villageId)) {
            $selected = (clone $query)
                ->where('shiply_id', (int) $villageId)
                ->first();

            if ($selected) {
                return $selected;
            }
        }

        return $query->orderBy('name')->first();
    }

    private function deliveryFeeForShiplyCity(
        ?StoreShiplyCity $shiplyCity,
        ?StoreShiplyVillage $shiplyVillage,
        float $parcelPrice,
        string $mode
    ): float {
        if (! $shiplyCity) {
            return 0.0;
        }

        $city = City::query()
            ->where('is_active', true)
            ->where(function ($query) use ($shiplyCity) {
                $query
                    ->where('shiply_area_code', (string) $shiplyCity->shiply_id)
                    ->orWhere('name_ar', $shiplyCity->name)
                    ->orWhere('name_en', $shiplyCity->name);
            })
            ->first();

        $fee = $city?->currentDeliveryFee();

        if ($fee !== null && (float) $fee > 0) {
            return round((float) $fee, 2);
        }

        if (! $shiplyVillage) {
            return 0.0;
        }

        try {
            $quote = app(ShiplyService::class)->calculateDeliveryCost(
                (int) $shiplyVillage->shiply_id,
                max(0, $parcelPrice),
                $mode
            );

            return round((float) ($quote['delivery_cost'] ?? 0), 2);
        } catch (\Throwable) {
            return 0.0;
        }
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
