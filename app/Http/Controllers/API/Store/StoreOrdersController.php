<?php

namespace App\Http\Controllers\API\Store;

use App\Models\Store\StoreProduct;
use App\Models\Store\StoreSalesOrder;
use App\Models\Store\StoreSalesOrderItem;
use App\Models\Store\StoreShiplyCity;
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
        $shiplyCity = is_numeric($cityId)
            ? StoreShiplyCity::query()->where('shiply_id', (int) $cityId)->first()
            : null;

        $order = DB::transaction(function () use ($request, $details, $cityId, $shiplyCity) {
            $subtotal = (float) $request->input('totalPriceWithOutDiscound', 0);
            $discounted = (float) $request->input('totalPriceWithDiscound', $subtotal);
            $delivery = (float) $request->input('priceDelivery', 0);
            $couponTotal = $request->input('totalPriceWithDiscoundCode');
            $total = ($couponTotal !== null ? (float) $couponTotal : $discounted) + $delivery;

            $order = StoreSalesOrder::query()->create([
                'serial_number' => null,
                'customer_id' => null,
                'customer_name' => $request->input('customerName'),
                'customer_phone' => $request->input('phoneNum1'),
                'customer_address' => $request->input('address'),
                'city_id' => null,
                'shiply_city_id' => is_numeric($cityId) ? (int) $cityId : null,
                'shiply_city_name' => $shiplyCity?->name,
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

            return $order->fresh(['details.product.subCategories', 'details.product.normalImages', 'details.product.viewImages', 'details.product.image3d', 'details.product.sizes.colors']);
        });

        return response()->json($this->orderPayload($order, $request->all()));
    }

    public function getAllOrdersByUserId(Request $request)
    {
        $userId = $request->query('userId', $request->input('userId'));
        $status = $request->query('statusOrder', $request->input('statusOrder'));

        $query = StoreSalesOrder::query()
            ->with(['details.product.subCategories', 'details.product.normalImages', 'details.product.viewImages', 'details.product.image3d', 'details.product.sizes.colors'])
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

    private function orderPayload(StoreSalesOrder $order, array $fallback = []): array
    {
        $cityId = $order->shiply_city_id ? (int) $order->shiply_city_id : (int) ($fallback['cityId'] ?? 0);
        $subtotal = (float) ($order->subtotal ?? 0);
        $discount = (float) ($order->discount ?? 0);

        return [
            'id' => (int) $order->id,
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

    private function nullableInt($value): ?int
    {
        if ($value === null || $value === '' || $value === 'null') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
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
}
