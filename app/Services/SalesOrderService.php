<?php

namespace App\Services;

use App\Enums\SalesOrderStatus;
use App\Support\ProductImageResolver;
use App\Models\City;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\Size;
use App\Models\SizeColor;
use App\Models\SalesOrderPackage;
use App\Models\SalesOrderStatusLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesOrderService
{
    public function __construct(
        protected DocumentSerialService $serialService,
        protected SalesOrderStockService $stockService,
        protected SalesOrderFulfillmentService $fulfillmentService,
        protected SalesOrderNotificationService $notifications,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function list(array $filters = []): array
    {
        $query = SalesOrder::query()
            ->with(['customer:id,name,phone', 'city:id,name_ar', 'createdByUser:id,name'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function ($q) use ($search) {
                $q->where('serial_number', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%")
                    ->orWhere('customer_phone', 'like', "%{$search}%");
            });
        }

        if (empty($filters['include_hidden'])) {
            $query->where(function ($q) {
                $q->whereNull('hidden_until')
                    ->orWhere('hidden_until', '<=', now());
            });
        }

        if (! empty($filters['from_date'])) {
            $query->whereDate('created_at', '>=', $filters['from_date']);
        }

        if (! empty($filters['to_date'])) {
            $query->whereDate('created_at', '<=', $filters['to_date']);
        }

        return $query->limit(500)->get()
            ->map(fn (SalesOrder $order) => $this->formatListItem($order))
            ->values()
            ->all();
    }

    public function show(int $orderId): SalesOrder
    {
        return SalesOrder::query()
            ->with([
                'customer:id,name,phone,address',
                'city:id,name_ar,name_en',
                'items.product:id,nameAr',
                'packages',
                'statusLogs.user:id,name',
                'deliveryCompany:id,name,code',
                'media',
                'deliveries',
                'childOrders:id,parent_order_id,serial_number,status,total,created_at',
                'createdByUser:id,name',
            ])
            ->findOrFail($orderId);
    }

    public function store(User $user, Request $request): SalesOrder
    {
        $data = $this->validateOrderPayload($request, false);

        return DB::transaction(function () use ($user, $data) {
            $totals = $this->calculateTotals($data);
            $customerSnapshot = $this->resolveCustomerSnapshot($data);

            $order = SalesOrder::create([
                'customer_id' => $customerSnapshot['customer_id'],
                'customer_name' => $customerSnapshot['customer_name'],
                'customer_phone' => $customerSnapshot['customer_phone'],
                'customer_address' => $customerSnapshot['customer_address'],
                'city_id' => $data['city_id'] ?? null,
                'status' => SalesOrderStatus::Unconfirmed->value,
                'parent_order_id' => $data['parent_order_id'] ?? null,
                'root_order_id' => $data['root_order_id'] ?? null,
                'payment_type' => $data['payment_type'] ?? 'cash',
                'payment_box_id' => $data['payment_box_id'] ?? null,
                'payment_amount' => $data['payment_amount'] ?? 0,
                'delivery_company_id' => $data['delivery_company_id'] ?? null,
                'delivery_company_name' => $data['delivery_company_name'] ?? null,
                'customer_delivery_fee' => $totals['customer_delivery_fee'],
                'subtotal' => $totals['subtotal'],
                'discount' => $totals['discount'],
                'total' => $totals['total'],
                'debt_id' => $data['debt_id'] ?? null,
                'is_debt_collection' => (bool) ($data['is_debt_collection'] ?? false),
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);

            if (empty($order->root_order_id)) {
                $order->update(['root_order_id' => $order->id]);
            }

            $this->serialService->assignToModel(
                $order,
                DocumentSerialService::TYPE_SALES_ORDER,
                'serial_number',
                $order->created_at
            );

            if (! $order->is_debt_collection) {
                $this->syncItems($order, $data['items'] ?? [], $data['packages'] ?? []);
            }

            $this->logStatus($order, null, SalesOrderStatus::Unconfirmed->value, 'إنشاء طلبية', $user->id);

            return $order->fresh($this->detailRelations());
        });
    }

    public function update(User $user, int $orderId, Request $request): SalesOrder
    {
        $order = SalesOrder::query()->with('items')->findOrFail($orderId);

        if (! $order->statusEnum()->isEditable()) {
            throw ValidationException::withMessages([
                'order' => [__('messages.sales_order_not_editable')],
            ]);
        }

        $data = $this->validateOrderPayload($request, true, $order);
        $totals = $this->calculateTotals($data, $order);
        $customerSnapshot = $this->resolveCustomerSnapshot($data, $order);

        return DB::transaction(function () use ($user, $order, $data, $totals, $customerSnapshot) {
            $order->update([
                'customer_id' => $customerSnapshot['customer_id'],
                'customer_name' => $customerSnapshot['customer_name'],
                'customer_phone' => $customerSnapshot['customer_phone'],
                'customer_address' => $customerSnapshot['customer_address'],
                'city_id' => $data['city_id'] ?? $order->city_id,
                'payment_type' => $data['payment_type'] ?? $order->payment_type,
                'payment_box_id' => $data['payment_box_id'] ?? $order->payment_box_id,
                'payment_amount' => $data['payment_amount'] ?? $order->payment_amount,
                'delivery_company_id' => $data['delivery_company_id'] ?? $order->delivery_company_id,
                'delivery_company_name' => $data['delivery_company_name'] ?? $order->delivery_company_name,
                'customer_delivery_fee' => $totals['customer_delivery_fee'],
                'subtotal' => $totals['subtotal'],
                'discount' => $totals['discount'],
                'total' => $totals['total'],
                'debt_id' => $data['debt_id'] ?? $order->debt_id,
                'is_debt_collection' => (bool) ($data['is_debt_collection'] ?? $order->is_debt_collection),
                'notes' => $data['notes'] ?? $order->notes,
                'updated_by' => $user->id,
            ]);

            if (! $order->is_debt_collection && array_key_exists('items', $data)) {
                $order->items()->delete();
                $order->packages()->delete();
                $this->syncItems($order, $data['items'] ?? [], $data['packages'] ?? []);
            }

            $this->stockService->syncReservationsAfterEdit($order->fresh(['items.product']));

            return $order->fresh($this->detailRelations());
        });
    }

    public function confirm(User $user, int $orderId): SalesOrder
    {
        $order = $this->show($orderId);
        $this->assertTransition($order, [
            SalesOrderStatus::Unconfirmed,
            SalesOrderStatus::Postponed,
        ]);

        if ($order->is_debt_collection) {
            return DB::transaction(function () use ($user, $order) {
                $from = $order->status;
                $order->update([
                    'status' => SalesOrderStatus::Confirmed->value,
                    'postponed_until' => null,
                    'postpone_reason' => null,
                    'updated_by' => $user->id,
                ]);
                $this->logStatus($order, $from, SalesOrderStatus::Confirmed->value, 'تأكيد طلبية تحصيل دين', $user->id);
                $this->notifications->notifyStatusChange($order->fresh(), $from, SalesOrderStatus::Confirmed->value, $user);

                return $order->fresh($this->detailRelations());
            });
        }

        if ($order->items->where('is_hidden', false)->isEmpty()) {
            throw ValidationException::withMessages([
                'items' => [__('messages.sales_order_requires_items')],
            ]);
        }

        return DB::transaction(function () use ($user, $order) {
            $this->stockService->reserveOrder($order);
            $from = $order->status;
            $order->update([
                'status' => SalesOrderStatus::Confirmed->value,
                'postponed_until' => null,
                'postpone_reason' => null,
                'updated_by' => $user->id,
            ]);
            $this->logStatus($order, $from, SalesOrderStatus::Confirmed->value, 'تأكيد الطلبية', $user->id);
            $this->notifications->notifyStatusChange($order->fresh(), $from, SalesOrderStatus::Confirmed->value, $user);

            return $order->fresh($this->detailRelations());
        });
    }

    public function markReady(User $user, int $orderId): SalesOrder
    {
        $order = SalesOrder::query()->findOrFail($orderId);
        $this->assertTransition($order, [SalesOrderStatus::Confirmed]);

        return DB::transaction(function () use ($user, $order) {
            $from = $order->status;
            $order->update([
                'status' => SalesOrderStatus::Ready->value,
                'updated_by' => $user->id,
            ]);
            $this->logStatus($order, $from, SalesOrderStatus::Ready->value, 'تجهيز الطلبية', $user->id);
            $this->notifications->notifyStatusChange($order->fresh(), $from, SalesOrderStatus::Ready->value, $user);

            return $order->fresh($this->detailRelations());
        });
    }

    public function cancel(User $user, int $orderId, ?string $note = null): SalesOrder
    {
        $order = SalesOrder::query()->findOrFail($orderId);

        if ($order->statusEnum() === SalesOrderStatus::WithDelivery) {
            return $this->fulfillmentService->markReturned($user, $orderId, $note);
        }

        if (! $order->statusEnum()->canCancel()) {
            throw ValidationException::withMessages([
                'order' => [__('messages.sales_order_invalid_status_transition')],
            ]);
        }

        return DB::transaction(function () use ($user, $order, $note) {
            $this->stockService->releaseOrder($order);
            $from = $order->status;
            $order->update([
                'status' => SalesOrderStatus::Canceled->value,
                'updated_by' => $user->id,
            ]);
            $this->logStatus($order, $from, SalesOrderStatus::Canceled->value, $note ?? 'إلغاء الطلبية', $user->id);
            $this->notifications->notifyStatusChange($order->fresh(), $from, SalesOrderStatus::Canceled->value, $user, $note);

            return $order->fresh($this->detailRelations());
        });
    }

    public function postpone(User $user, int $orderId, string $until, ?string $reason = null): SalesOrder
    {
        $order = SalesOrder::query()->findOrFail($orderId);
        $this->assertTransition($order, [
            SalesOrderStatus::Unconfirmed,
            SalesOrderStatus::Confirmed,
            SalesOrderStatus::Ready,
        ]);

        return DB::transaction(function () use ($user, $order, $until, $reason) {
            $from = $order->status;
            $order->update([
                'status' => SalesOrderStatus::Postponed->value,
                'postponed_until' => $until,
                'postpone_reason' => $reason,
                'updated_by' => $user->id,
            ]);
            $this->logStatus($order, $from, SalesOrderStatus::Postponed->value, $reason ?? 'تأجيل الطلبية', $user->id);
            $this->notifications->notifyStatusChange($order->fresh(), $from, SalesOrderStatus::Postponed->value, $user, $reason);

            return $order->fresh($this->detailRelations());
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function formatDetail(SalesOrder $order): array
    {
        $order->loadMissing($this->detailRelations());

        return [
            'id' => $order->id,
            'serial_number' => $order->serial_number,
            'status' => $order->status,
            'customer_id' => $order->customer_id,
            'customer_name' => $order->customer_name,
            'customer_phone' => $order->customer_phone,
            'customer_address' => $order->customer_address,
            'city_id' => $order->city_id,
            'city' => $order->city ? [
                'id' => $order->city->id,
                'name_ar' => $order->city->name_ar,
                'name_en' => $order->city->name_en,
            ] : null,
            'payment_type' => $order->payment_type,
            'payment_box_id' => $order->payment_box_id,
            'payment_amount' => (float) $order->payment_amount,
            'delivery_company_id' => $order->delivery_company_id,
            'delivery_company_name' => $order->delivery_company_name,
            'customer_delivery_fee' => (float) $order->customer_delivery_fee,
            'carrier_delivery_cost' => $order->carrier_delivery_cost !== null
                ? (float) $order->carrier_delivery_cost
                : null,
            'subtotal' => (float) $order->subtotal,
            'discount' => (float) $order->discount,
            'total' => (float) $order->total,
            'debt_id' => $order->debt_id,
            'is_debt_collection' => (bool) $order->is_debt_collection,
            'instant_sale_id' => $order->instant_sale_id,
            'instant_sale_serial' => $order->instantSale?->serial_number,
            'hidden_until' => $order->hidden_until?->toDateTimeString(),
            'postponed_until' => $order->postponed_until?->toDateTimeString(),
            'postpone_reason' => $order->postpone_reason,
            'notes' => $order->notes,
            'parent_order_id' => $order->parent_order_id,
            'root_order_id' => $order->root_order_id,
            'created_by' => $order->createdByUser ? [
                'id' => $order->createdByUser->id,
                'name' => $order->createdByUser->name,
            ] : null,
            'created_at' => $order->created_at?->toDateTimeString(),
            'updated_at' => $order->updated_at?->toDateTimeString(),
            'stock_deducted_at' => $order->stock_deducted_at?->toDateTimeString(),
            'financial_posted_at' => $order->financial_posted_at?->toDateTimeString(),
            'delivery_settled_at' => $order->delivery_settled_at?->toDateTimeString(),
            'delivery_settled_amount' => $order->delivery_settled_amount !== null
                ? (float) $order->delivery_settled_amount
                : null,
            'archived_at' => $order->archived_at?->toDateTimeString(),
            'items' => $order->items->map(function (SalesOrderItem $item) {
                $productImage = $item->relationLoaded('product') && $item->product
                    ? ProductImageResolver::preferredUrl($item->product)
                    : 'no image';

                return [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product_name' => $item->product_name,
                'product_image' => $productImage !== 'no image' ? $productImage : null,
                'size_id' => $item->size_id,
                'size_color_id' => $item->size_color_id,
                'size_label' => $item->size?->size,
                'color_label' => $item->sizeColor?->colorAr,
                'quantity' => (int) $item->quantity,
                'reserved_qty' => (int) $item->reserved_qty,
                'dispatched_qty' => (int) $item->dispatched_qty,
                'delivered_qty' => (int) ($item->delivered_qty ?? 0),
                'returned_qty' => (int) ($item->returned_qty ?? 0),
                'unit_price' => (float) $item->unit_price,
                'line_total' => (float) $item->line_total,
                'is_hidden' => (bool) $item->is_hidden,
                'sales_order_package_id' => $item->sales_order_package_id,
            ];
            })->values()->all(),
            'packages' => $order->packages->map(fn (SalesOrderPackage $pkg) => [
                'id' => $pkg->id,
                'package_index' => (int) $pkg->package_index,
                'status' => $pkg->status,
                'customer_delivery_fee' => (float) $pkg->customer_delivery_fee,
                'tracking_number' => $pkg->tracking_number,
            ])->values()->all(),
            'status_logs' => $order->statusLogs->map(fn (SalesOrderStatusLog $log) => [
                'from_status' => $log->from_status,
                'to_status' => $log->to_status,
                'note' => $log->note,
                'user_name' => $log->user?->name,
                'created_at' => $log->created_at?->toDateTimeString(),
            ])->values()->all(),
            'media' => $order->media->map(fn ($media) => [
                'id' => $media->id,
                'type' => $media->type,
                'path' => $media->path,
                'url' => $media->path ? url('storage/'.$media->path) : null,
                'status_at_upload' => $media->status_at_upload,
            ])->values()->all(),
            'deliveries' => $order->deliveries->map(fn ($delivery) => [
                'id' => $delivery->id,
                'tracking_number' => $delivery->tracking_number,
                'delivery_company_name' => $delivery->delivery_company_name,
                'handed_over_at' => $delivery->handed_over_at?->toDateTimeString(),
                'delivered_at' => $delivery->delivered_at?->toDateTimeString(),
            ])->values()->all(),
            'child_orders' => $order->childOrders->map(fn ($child) => [
                'id' => $child->id,
                'serial_number' => $child->serial_number,
                'status' => $child->status,
                'total' => (float) $child->total,
                'created_at' => $child->created_at?->toDateTimeString(),
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatListItem(SalesOrder $order): array
    {
        return [
            'id' => $order->id,
            'serial_number' => $order->serial_number,
            'status' => $order->status,
            'customer_name' => $order->customer_name,
            'customer_phone' => $order->customer_phone,
            'city_name' => $order->city?->name_ar,
            'total' => (float) $order->total,
            'payment_type' => $order->payment_type,
            'created_at' => $order->created_at?->toDateTimeString(),
            'created_by_name' => $order->createdByUser?->name,
        ];
    }

    /**
     * @return list<string>
     */
    private function detailRelations(): array
    {
        return [
            'customer:id,name,phone,address',
            'city:id,name_ar,name_en',
            'items.size:id,size',
            'items.sizeColor:id,colorAr',
            'items.product.normalImages',
            'items.product.viewImages',
            'items.product.image3d',
            'instantSale:id,serial_number',
            'packages',
            'statusLogs.user:id,name',
            'deliveryCompany:id,name,code',
            'media',
            'deliveries',
            'childOrders:id,parent_order_id,serial_number,status,total,created_at',
            'createdByUser:id,name',
        ];
    }

    private function assertTransition(SalesOrder $order, array $allowed): void
    {
        $current = $order->statusEnum();
        $allowedEnums = array_map(
            fn ($s) => $s instanceof SalesOrderStatus ? $s : SalesOrderStatus::normalize($s),
            $allowed
        );

        if (! in_array($current, $allowedEnums, true)) {
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

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validateOrderPayload(Request $request, bool $isUpdate, ?SalesOrder $order = null): array
    {
        $rules = [
            'customer_id' => 'nullable|integer|exists:customers,id',
            'customer_name' => 'nullable|string|max:255',
            'customer_phone' => 'nullable|string|max:50',
            'customer_address' => 'nullable|string|max:500',
            'city_id' => 'nullable|integer|exists:cities,id',
            'payment_type' => 'nullable|string|in:cash,credit,mixed',
            'payment_box_id' => 'nullable|integer|exists:boxes,id',
            'payment_amount' => 'nullable|numeric|min:0',
            'delivery_company_id' => 'nullable|integer|exists:delivery_companies,id',
            'delivery_company_name' => 'nullable|string|max:255',
            'customer_delivery_fee' => 'nullable|numeric|min:0',
            'discount' => 'nullable|numeric|min:0',
            'debt_id' => 'nullable|integer|exists:debts,id',
            'is_debt_collection' => 'nullable|boolean',
            'notes' => 'nullable|string|max:2000',
            'parent_order_id' => 'nullable|integer|exists:sales_orders,id',
            'root_order_id' => 'nullable|integer|exists:sales_orders,id',
            'items' => $isUpdate ? 'nullable|array' : 'required_without:is_debt_collection|array',
            'items.*.product_id' => 'required_with:items|integer|exists:products,id',
            'items.*.size_id' => 'nullable|integer',
            'items.*.size_color_id' => 'nullable|integer',
            'items.*.quantity' => 'required_with:items|integer|min:1',
            'items.*.unit_price' => 'required_with:items|numeric|min:0',
            'items.*.is_hidden' => 'nullable|boolean',
            'items.*.package_index' => 'nullable|integer|min:1|max:2',
            'packages' => 'nullable|array|max:2',
            'packages.*.package_index' => 'required_with:packages|integer|min:1|max:2',
            'packages.*.customer_delivery_fee' => 'nullable|numeric|min:0',
        ];

        $data = $request->validate($rules);

        $isDebtCollection = (bool) ($data['is_debt_collection'] ?? ($order?->is_debt_collection ?? false));

        if ($isDebtCollection) {
            if (! empty($data['items'])) {
                throw ValidationException::withMessages([
                    'items' => [__('messages.sales_order_debt_collection_no_items')],
                ]);
            }
            if (empty($data['debt_id']) && empty($order?->debt_id)) {
                throw ValidationException::withMessages([
                    'debt_id' => [__('messages.sales_order_debt_required')],
                ]);
            }
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{customer_id: ?int, customer_name: ?string, customer_phone: ?string, customer_address: ?string}
     */
    private function resolveCustomerSnapshot(array $data, ?SalesOrder $order = null): array
    {
        if (! empty($data['customer_id'])) {
            $customer = Customer::query()->find($data['customer_id']);
            if ($customer) {
                return [
                    'customer_id' => (int) $customer->id,
                    'customer_name' => $customer->name,
                    'customer_phone' => $customer->phone,
                    'customer_address' => $customer->address,
                ];
            }
        }

        return [
            'customer_id' => $data['customer_id'] ?? $order?->customer_id,
            'customer_name' => $data['customer_name'] ?? $order?->customer_name,
            'customer_phone' => $data['customer_phone'] ?? $order?->customer_phone,
            'customer_address' => $data['customer_address'] ?? $order?->customer_address,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{subtotal: float, discount: float, customer_delivery_fee: float, total: float}
     */
    private function calculateTotals(array $data, ?SalesOrder $order = null): array
    {
        $discount = (float) ($data['discount'] ?? $order?->discount ?? 0);
        $subtotal = 0.0;

        foreach ($data['items'] ?? [] as $item) {
            $qty = (int) ($item['quantity'] ?? 0);
            $price = (float) ($item['unit_price'] ?? 0);
            $subtotal += round($qty * $price, 2);
        }

        if (empty($data['items']) && $order) {
            $subtotal = (float) $order->subtotal;
        }

        $deliveryFee = isset($data['customer_delivery_fee'])
            ? (float) $data['customer_delivery_fee']
            : $this->resolveDeliveryFee($data, $order);

        $total = max(0, round($subtotal + $deliveryFee - $discount, 2));

        return [
            'subtotal' => round($subtotal, 2),
            'discount' => round($discount, 2),
            'customer_delivery_fee' => round($deliveryFee, 2),
            'total' => $total,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveDeliveryFee(array $data, ?SalesOrder $order = null): float
    {
        if (! empty($data['packages'])) {
            $sum = 0.0;
            foreach ($data['packages'] as $pkg) {
                $sum += (float) ($pkg['customer_delivery_fee'] ?? 0);
            }

            return round($sum, 2);
        }

        if (! empty($data['city_id'])) {
            $city = City::query()->find($data['city_id']);
            $fee = $city?->currentDeliveryFee();

            return $fee !== null ? round($fee, 2) : 0.0;
        }

        return (float) ($order?->customer_delivery_fee ?? 0);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<int, array<string, mixed>>  $packages
     */
    private function syncItems(SalesOrder $order, array $items, array $packages = []): void
    {
        $packageMap = [];

        foreach ($packages as $pkg) {
            $index = (int) ($pkg['package_index'] ?? 1);
            $packageMap[$index] = SalesOrderPackage::create([
                'sales_order_id' => $order->id,
                'package_index' => $index,
                'status' => 'pending',
                'customer_delivery_fee' => (float) ($pkg['customer_delivery_fee'] ?? 0),
            ]);
        }

        if ($packageMap === [] && count($items) > 0) {
            $packageMap[1] = SalesOrderPackage::create([
                'sales_order_id' => $order->id,
                'package_index' => 1,
                'status' => 'pending',
                'customer_delivery_fee' => (float) $order->customer_delivery_fee,
            ]);
        }

        foreach ($items as $item) {
            $product = Product::query()->find($item['product_id']);
            $qty = (int) $item['quantity'];
            $unitPrice = (float) $item['unit_price'];
            $packageIndex = (int) ($item['package_index'] ?? 1);

            $sizeLabel = $item['size_label'] ?? null;
            $colorLabel = $item['color_label'] ?? null;
            if ($sizeLabel === null && ! empty($item['size_id'])) {
                $sizeLabel = Size::query()->find($item['size_id'])?->size;
            }
            if ($colorLabel === null && ! empty($item['size_color_id'])) {
                $colorLabel = SizeColor::query()->find($item['size_color_id'])?->colorAr;
            }

            $productName = $product?->nameAr;
            if ($sizeLabel && $colorLabel) {
                $productName = trim((string) $productName).' — '.$sizeLabel.' / '.$colorLabel;
            }

            SalesOrderItem::create([
                'sales_order_id' => $order->id,
                'sales_order_package_id' => $packageMap[$packageIndex]->id ?? null,
                'product_id' => (int) $item['product_id'],
                'size_id' => $item['size_id'] ?? null,
                'size_color_id' => $item['size_color_id'] ?? null,
                'product_name' => $productName,
                'quantity' => $qty,
                'unit_price' => $unitPrice,
                'line_total' => round($qty * $unitPrice, 2),
                'is_hidden' => (bool) ($item['is_hidden'] ?? false),
            ]);
        }
    }
}
