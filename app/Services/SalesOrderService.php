<?php

namespace App\Services;

use App\Enums\SalesOrderStatus;
use App\Support\ProductImageResolver;
use App\Support\ShiplySettings;
use App\Models\City;
use App\Models\Customer;
use App\Models\Product;
use App\Models\DeliveryCompany;
use App\Models\SalesOrder;
use App\Models\SalesOrderDelivery;
use App\Models\SalesOrderItem;
use App\Models\Size;
use App\Models\SizeColor;
use App\Models\SalesOrderPackage;
use App\Models\SalesOrderStatusLog;
use App\Models\ShiplyCity;
use App\Models\ShiplyVillage;
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
        protected SalesOrderShiplyTrackingService $shiplyTracking,
        protected ShiplyService $shiplyService,
        protected SalesOrderMediaRequirementService $mediaRequirements,
        protected SalesOrderStockShortageService $shortages,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array{has_conflicts: bool, conflicts: list<array<string, mixed>>}
     */
    public function checkStockImpact(array $filters): array
    {
        $items = $filters['items'] ?? [];
        $excludeOrderId = ! empty($filters['sales_order_id']) ? (int) $filters['sales_order_id'] : null;
        $conflicts = $this->stockService->analyzeItemsStockImpact($items, $excludeOrderId);

        return [
            'has_conflicts' => $conflicts !== [],
            'conflicts' => $conflicts,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function productStockAvailability(array $filters): array
    {
        $productIds = array_map('intval', $filters['product_ids'] ?? []);
        $excludeOrderId = ! empty($filters['sales_order_id']) ? (int) $filters['sales_order_id'] : null;

        return $this->stockService->bulkAvailability($productIds, $excludeOrderId);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function guardReservedStockConflicts(SalesOrder $order, array $data): void
    {
        if ($order->stock_deducted_at || ! $order->statusEnum()->reservesStock()) {
            return;
        }

        if ($this->shouldAllowNegativeStock($data, $order)) {
            return;
        }

        $conflicts = $this->stockService->analyzeOrderStockImpact($order);
        if ($conflicts === []) {
            return;
        }

        throw ValidationException::withMessages([
            'acknowledge_negative_stock' => [__('messages.sales_order_reserved_stock_conflict')],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function applyUnconfirmedStockReservation(SalesOrder $order, array $data, User $user): void
    {
        if (! $order->statusEnum()->reservesStock() || $order->stock_deducted_at) {
            return;
        }

        $allowNegative = $this->shouldAllowNegativeStock($data, $order);

        $this->stockService->reserveOrder($order, $allowNegative);

        if ($allowNegative) {
            $this->shortages->syncAndNotify(
                $order->fresh(['items']),
                $this->stockService->analyzeOrderStockImpact($order),
                $user
            );
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function shouldAllowNegativeStock(array $data, ?SalesOrder $order = null): bool
    {
        if ((bool) ($data['acknowledge_negative_stock'] ?? false)) {
            return true;
        }

        if ($order === null) {
            return false;
        }

        $conflicts = $this->stockService->analyzeOrderStockImpact($order);

        return $conflicts === [];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function list(array $filters = []): array
    {
        $query = SalesOrder::query()
            ->with([
                'customer:id,name,phone',
                'city:id,name_ar',
                'createdByUser:id,name',
                'deliveryCompany:id,name,code',
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $this->applyListFilters($query, $filters, includeStatus: true);

        return $query->limit(500)->get()
            ->map(fn (SalesOrder $order) => $this->formatListItem($order))
            ->values()
            ->all();
    }

    /**
     * Counts every status using the same filters as the list, except the
     * currently selected status so the client can build all status tabs.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, int>
     */
    public function statusCounts(array $filters = []): array
    {
        $query = SalesOrder::query();
        $this->applyListFilters($query, $filters, includeStatus: false);

        $counts = (clone $query)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count) => (int) $count)
            ->all();

        $counts['settlement'] = (clone $query)
            ->where('status', SalesOrderStatus::Delivered->value)
            ->where(function ($balanceQuery) {
                $balanceQuery->where('customer_debt_balance', '>', 0)
                    ->orWhere('carrier_receivable_balance', '>', 0);
            })
            ->count();

        return $counts;
    }

    /** @param \Illuminate\Database\Eloquent\Builder<SalesOrder> $query */
    private function applyListFilters($query, array $filters, bool $includeStatus): void
    {

        if ($includeStatus && ! empty($filters['status'])) {
            if ($filters['status'] === 'all') {
                $query->where('status', '!=', SalesOrderStatus::Archived->value);
            } elseif ($filters['status'] === 'settlement') {
                $query->where('status', SalesOrderStatus::Delivered->value)
                    ->where(function ($balanceQuery) {
                        $balanceQuery->where('customer_debt_balance', '>', 0)
                            ->orWhere('carrier_receivable_balance', '>', 0);
                    });
            } else {
                $query->where('status', $filters['status']);
            }
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

        if (! empty($filters['has_stock_shortage'])) {
            $query->whereHas('stockShortages', fn ($q) => $q->where('status', 'open'));
        }
        if (! empty($filters['has_customer_debt'])) {
            $query->where('customer_debt_balance', '>', 0);
        }
        if (! empty($filters['has_carrier_receivable'])) {
            $query->where('carrier_receivable_balance', '>', 0);
        }
        if (! empty($filters['stuck_assigned_to'])) {
            $query->where('stuck_assigned_to', (int) $filters['stuck_assigned_to']);
        }
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
                'partner_type' => $data['partner_type'] ?? ($customerSnapshot['customer_id'] ? 'customer' : null),
                'partner_id' => $data['partner_id'] ?? $customerSnapshot['customer_id'],
                'customer_name' => $customerSnapshot['customer_name'],
                'customer_phone' => $customerSnapshot['customer_phone'],
                'customer_address' => $this->normalizeStreetAddress($customerSnapshot['customer_address']),
                'partner_address_id' => $data['partner_address_id'] ?? null,
                'address_snapshot' => $this->addressSnapshot($data, $customerSnapshot),
                'city_id' => $data['city_id'] ?? null,
                'shiply_city_id' => $data['shiply_city_id'] ?? null,
                'shiply_village_id' => $data['shiply_village_id'] ?? null,
                'shiply_city_name' => $data['shiply_city_name'] ?? null,
                'shiply_village_name' => $data['shiply_village_name'] ?? null,
                'status' => SalesOrderStatus::Unconfirmed->value,
                'parent_order_id' => $data['parent_order_id'] ?? null,
                'root_order_id' => $data['root_order_id'] ?? null,
                'payment_type' => $data['payment_type'] ?? 'cash',
                'payment_box_id' => $data['payment_box_id'] ?? null,
                'payment_amount' => $data['payment_amount'] ?? 0,
                'delivery_company_id' => $data['delivery_company_id'] ?? null,
                'delivery_company_name' => $data['delivery_company_name'] ?? null,
                'customer_delivery_fee' => $totals['customer_delivery_fee'],
                'price_includes_delivery' => (bool) ($data['price_includes_delivery'] ?? false),
                'shiply_quoted_delivery_fee' => $data['shiply_quoted_delivery_fee'] ?? null,
                'subtotal' => $totals['subtotal'],
                'discount' => $totals['discount'],
                'calculated_total' => $totals['calculated_total'],
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

            $this->fulfillmentService->postInitialPayment($order, $user);

            if (! $order->is_debt_collection) {
                $this->syncItems($order, $data['items'] ?? [], $data['packages'] ?? []);
                $freshOrder = $order->fresh(['items.product']);
                $this->guardReservedStockConflicts($freshOrder, $data);
                $this->applyUnconfirmedStockReservation($freshOrder, $data, $user);
            }

            $this->logStatus($order, null, SalesOrderStatus::Unconfirmed->value, 'إنشاء طلبية', $user->id);

            $this->notifications->notifyStatusChange(
                $order->fresh(),
                null,
                SalesOrderStatus::Unconfirmed->value,
                $user,
                __('messages.sales_order_created_note')
            );

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
        if (array_key_exists('payment_amount', $data)) {
            $postedPayment = round((float) $order->settlements()
                ->where('source', 'order_payment')
                ->sum('amount'), 2);
            $requestedPayment = round((float) $data['payment_amount'], 2);
            if ($postedPayment > 0 && abs($requestedPayment - $postedPayment) > 0.0001) {
                throw ValidationException::withMessages([
                    'payment_amount' => ['لا يمكن تعديل المبلغ المدفوع بعد ترحيله للصندوق؛ استخدم التسوية المالية.'],
                ]);
            }
        }

        return DB::transaction(function () use ($user, $order, $data, $totals, $customerSnapshot) {
            $order->update([
                'customer_id' => $customerSnapshot['customer_id'],
                'partner_type' => $data['partner_type'] ?? $order->partner_type,
                'partner_id' => $data['partner_id'] ?? $order->partner_id,
                'customer_name' => $customerSnapshot['customer_name'],
                'customer_phone' => $customerSnapshot['customer_phone'],
                'customer_address' => $this->normalizeStreetAddress($customerSnapshot['customer_address']),
                'partner_address_id' => array_key_exists('partner_address_id', $data)
                    ? $data['partner_address_id'] : $order->partner_address_id,
                'address_snapshot' => $this->addressSnapshot($data, $customerSnapshot, $order),
                'city_id' => $data['city_id'] ?? $order->city_id,
                'shiply_city_id' => $data['shiply_city_id'] ?? $order->shiply_city_id,
                'shiply_village_id' => $data['shiply_village_id'] ?? $order->shiply_village_id,
                'shiply_city_name' => $data['shiply_city_name'] ?? $order->shiply_city_name,
                'shiply_village_name' => $data['shiply_village_name'] ?? $order->shiply_village_name,
                'payment_type' => $data['payment_type'] ?? $order->payment_type,
                'payment_box_id' => $data['payment_box_id'] ?? $order->payment_box_id,
                'payment_amount' => $data['payment_amount'] ?? $order->payment_amount,
                'delivery_company_id' => $data['delivery_company_id'] ?? $order->delivery_company_id,
                'delivery_company_name' => $data['delivery_company_name'] ?? $order->delivery_company_name,
                'customer_delivery_fee' => $totals['customer_delivery_fee'],
                'price_includes_delivery' => (bool) ($data['price_includes_delivery']
                    ?? $order->price_includes_delivery),
                'shiply_quoted_delivery_fee' => array_key_exists('shiply_quoted_delivery_fee', $data)
                    ? $data['shiply_quoted_delivery_fee']
                    : $order->shiply_quoted_delivery_fee,
                'subtotal' => $totals['subtotal'],
                'discount' => $totals['discount'],
                'calculated_total' => $totals['calculated_total'],
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

            $freshOrder = $order->fresh(['items.product']);
            $this->guardReservedStockConflicts($freshOrder, $data);
            $this->stockService->syncReservationsAfterEdit(
                $freshOrder,
                (int) $user->id,
                $this->shouldAllowNegativeStock($data, $freshOrder)
            );
            if ((bool) ($data['acknowledge_negative_stock'] ?? false)) {
                $this->shortages->syncAndNotify(
                    $freshOrder->fresh(['items']),
                    $this->stockService->analyzeOrderStockImpact($freshOrder),
                    $user
                );
            }

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
                $this->fulfillmentService->postInitialPayment($order, $user);
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
            $this->fulfillmentService->postInitialPayment($order, $user);
            $order->loadMissing('items.product', 'media');
            $conflicts = $this->stockService->analyzeOrderStockImpact($order);
            $negativeStockWasAcknowledged = $order->stockShortages()
                ->where('status', 'open')->exists();

            if ($conflicts !== [] && ! $negativeStockWasAcknowledged) {
                throw ValidationException::withMessages([
                    'acknowledge_negative_stock' => [__('messages.sales_order_reserved_stock_conflict')],
                ]);
            }
            if ($conflicts !== []) {
                $this->shortages->syncAndNotify($order, $conflicts, $user);
            }

            $this->stockService->dispatchOrder($order, (int) $user->id);
            $from = $order->status;
            $order->update([
                'status' => SalesOrderStatus::Confirmed->value,
                'postponed_until' => null,
                'postpone_reason' => null,
                'stock_deducted_at' => now(),
                'updated_by' => $user->id,
            ]);
            $this->logStatus($order, $from, SalesOrderStatus::Confirmed->value, 'تأكيد الطلبية', $user->id);
            $this->notifications->notifyStatusChange($order->fresh(), $from, SalesOrderStatus::Confirmed->value, $user);

            return $order->fresh($this->detailRelations());
        });
    }

    public function markReady(User $user, int $orderId): SalesOrder
    {
        $order = SalesOrder::query()->with('media')->findOrFail($orderId);
        $this->assertTransition($order, [SalesOrderStatus::Confirmed]);
        $this->mediaRequirements->assertCategoriesPresent(
            $order,
            $this->mediaRequirements->requiredBeforeReady()
        );

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

    public function revertStatus(User $user, int $orderId, ?string $note = null): SalesOrder
    {
        $order = SalesOrder::query()->with(['items.product'])->findOrFail($orderId);

        if ($order->instant_sale_id || $order->financial_posted_at) {
            throw ValidationException::withMessages([
                'order' => [__('messages.sales_order_cannot_revert_after_delivery')],
            ]);
        }

        $previous = match ($order->statusEnum()) {
            SalesOrderStatus::Confirmed => SalesOrderStatus::Unconfirmed,
            SalesOrderStatus::Ready => SalesOrderStatus::Confirmed,
            SalesOrderStatus::WithDelivery => SalesOrderStatus::Ready,
            SalesOrderStatus::Postponed => SalesOrderStatus::Unconfirmed,
            default => null,
        };

        if ($previous === null) {
            throw ValidationException::withMessages([
                'order' => [__('messages.sales_order_cannot_revert_status')],
            ]);
        }

        return DB::transaction(function () use ($user, $order, $previous, $note) {
            $from = $order->status;

            if ($order->statusEnum() === SalesOrderStatus::WithDelivery) {
                $this->cancelActiveShiplyParcel($order);
                $order->packages()->update(['status' => 'pending']);
            } elseif ($previous === SalesOrderStatus::Unconfirmed) {
                if ($order->stock_deducted_at) {
                    $this->stockService->restoreDispatchedOrder($order, (int) $user->id);
                    $order->update(['stock_deducted_at' => null]);
                } else {
                    $this->stockService->releaseOrder($order);
                }
                $this->stockService->reserveOrder($order->fresh(['items.product']), allowNegative: true);
            }

            $order->update([
                'status' => $previous->value,
                'postponed_until' => null,
                'postpone_reason' => null,
                'updated_by' => $user->id,
            ]);

            $this->logStatus(
                $order,
                $from,
                $previous->value,
                $note ?? __('messages.sales_order_revert_note'),
                $user->id
            );
            $this->notifications->notifyStatusChange(
                $order->fresh(),
                $from,
                $previous->value,
                $user,
                $note
            );

            return $order->fresh($this->detailRelations());
        });
    }

    public function cancel(User $user, int $orderId, ?string $note = null): SalesOrder
    {
        $order = SalesOrder::query()->findOrFail($orderId);

        if (in_array($order->statusEnum(), [
            SalesOrderStatus::WithDelivery,
            SalesOrderStatus::PartialReturn,
            SalesOrderStatus::PartialDelivered,
            SalesOrderStatus::Review,
        ], true)) {
            return $this->fulfillmentService->markReturned($user, $orderId, $note);
        }

        if (! $order->statusEnum()->canCancel()) {
            throw ValidationException::withMessages([
                'order' => [__('messages.sales_order_invalid_status_transition')],
            ]);
        }

        return DB::transaction(function () use ($user, $order, $note) {
            $this->fulfillmentService->reverseFinancialsForCancellation($order, $user);

            if ($order->stock_deducted_at) {
                $this->stockService->restoreDispatchedOrder($order, (int) $user->id);
            } else {
                $this->stockService->releaseOrder($order);
            }

            $from = $order->status;
            $order->update([
                'status' => SalesOrderStatus::Canceled->value,
                'stock_deducted_at' => null,
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
            'partner_type' => $order->partner_type,
            'partner_id' => $order->partner_id,
            'partner_address_id' => $order->partner_address_id,
            'address_snapshot' => $order->address_snapshot,
            'customer_name' => $order->customer_name,
            'customer_phone' => $order->customer_phone,
            'customer_address' => $order->customer_address,
            'city_id' => $order->city_id,
            'city' => $order->city ? [
                'id' => $order->city->id,
                'name_ar' => $order->city->name_ar,
                'name_en' => $order->city->name_en,
            ] : null,
            'shiply_city_id' => $order->shiply_city_id,
            'shiply_village_id' => $order->shiply_village_id,
            'shiply_city_name' => $order->shiply_city_name,
            'shiply_village_name' => $order->shiply_village_name,
            'shiply_address_label' => $this->formatShiplyAddressLabel($order),
            'is_shiply_delivery' => $this->isShiplyDeliveryCompany($order),
            'payment_type' => $order->payment_type,
            'payment_box_id' => $order->payment_box_id,
            'payment_amount' => (float) $order->payment_amount,
            'delivery_company_id' => $order->delivery_company_id,
            'delivery_company_name' => $order->delivery_company_name,
            'delivery_company_code' => $order->deliveryCompany?->code
                ? strtolower((string) $order->deliveryCompany->code)
                : null,
            'latest_handover' => ($latestDelivery = $order->deliveries->sortByDesc('id')->first())
                ? $this->formatDeliveryRecord($latestDelivery, $order)
                : null,
            'tracking_number' => $latestDelivery?->tracking_number
                ?? $order->deliveries->sortByDesc('id')->first()?->tracking_number
                ?? $order->packages->sortByDesc('id')->first()?->tracking_number,
            'customer_delivery_fee' => (float) $order->customer_delivery_fee,
            'price_includes_delivery' => (bool) $order->price_includes_delivery,
            'shiply_quoted_delivery_fee' => $order->shiply_quoted_delivery_fee !== null
                ? (float) $order->shiply_quoted_delivery_fee
                : null,
            'shiply_delivery_fee_adjustment' => $order->shiply_quoted_delivery_fee !== null
                ? round((float) $order->customer_delivery_fee - (float) $order->shiply_quoted_delivery_fee, 2)
                : null,
            'carrier_delivery_cost' => $order->carrier_delivery_cost !== null
                ? (float) $order->carrier_delivery_cost
                : null,
            'subtotal' => (float) $order->subtotal,
            'discount' => (float) $order->discount,
            'calculated_total' => (float) ($order->calculated_total ?? $order->total),
            'total' => (float) $order->total,
            'debt_id' => $order->debt_id,
            'is_debt_collection' => (bool) $order->is_debt_collection,
            'instant_sale_id' => $order->instant_sale_id,
            'instant_sale_serial' => $order->instantSale?->serial_number,
            'hidden_until' => $order->hidden_until?->toDateTimeString(),
            'postponed_until' => $order->postponed_until?->toDateTimeString(),
            'postpone_reason' => $order->postpone_reason,
            'notes' => $order->notes,
            'stuck_previous_status' => $order->stuck_previous_status,
            'stuck_type' => $order->stuck_type,
            'stuck_reason' => $order->stuck_reason,
            'stuck_assigned_to' => $order->stuck_assigned_to,
            'stuck_assigned_to_name' => $order->stuckAssignedUser?->name,
            'stuck_follow_up_at' => $order->stuck_follow_up_at?->toIso8601String(),
            'stuck_resolved_at' => $order->stuck_resolved_at?->toIso8601String(),
            'customer_debt_balance' => (float) $order->customer_debt_balance,
            'carrier_receivable_balance' => (float) $order->carrier_receivable_balance,
            'settlements' => $order->settlements->map(fn ($settlement) => [
                'id' => $settlement->id,
                'source' => $settlement->source,
                'amount' => (float) $settlement->amount,
                'box_id' => $settlement->box_id,
                'created_at' => $settlement->created_at?->toIso8601String(),
                'created_by' => $settlement->createdBy?->name,
                'notes' => $settlement->notes,
            ])->values(),
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
            'delivery_settled_box_id' => $order->delivery_settled_box_id,
            'media_requirements' => $this->mediaRequirements->buildRequirementsPayload($order),
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
                'category' => $media->category ?? 'general',
                'path' => $media->path,
                'url' => $media->path ? url('storage/'.$media->path) : null,
                'status_at_upload' => $media->status_at_upload,
            ])->values()->all(),
            'deliveries' => $order->deliveries
                ->sortByDesc('id')
                ->map(fn ($delivery) => $this->formatDeliveryRecord($delivery, $order))
                ->values()
                ->all(),
            'child_orders' => $order->childOrders->map(fn ($child) => [
                'id' => $child->id,
                'serial_number' => $child->serial_number,
                'status' => $child->status,
                'total' => (float) $child->total,
                'created_at' => $child->created_at?->toDateTimeString(),
            ])->values()->all(),
            'shiply_tracking' => $this->shiplyTracking->buildTrackingPayload($order),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatDeliveryRecord(SalesOrderDelivery $delivery, ?SalesOrder $order = null): array
    {
        $companyCode = null;
        if ($delivery->delivery_company_id) {
            $companyCode = DeliveryCompany::query()
                ->where('id', $delivery->delivery_company_id)
                ->value('code');
        }
        $companyCode ??= $order?->deliveryCompany?->code;

        return [
            'id' => $delivery->id,
            'delivery_company_id' => $delivery->delivery_company_id,
            'delivery_company_name' => $delivery->delivery_company_name,
            'delivery_company_code' => $companyCode !== null
                ? strtolower(trim((string) $companyCode))
                : null,
            'tracking_number' => $delivery->tracking_number,
            'carrier_contact_name' => $delivery->carrier_contact_name,
            'carrier_contact_phone' => $delivery->carrier_contact_phone,
            'carrier_office_name' => $delivery->carrier_office_name,
            'carrier_vehicle_number' => $delivery->carrier_vehicle_number,
            'shiply_parcel_code' => $delivery->shiply_parcel_code,
            'shiply_qr_code' => $delivery->shiply_qr_code,
            'handed_over_at' => $delivery->handed_over_at?->toDateTimeString(),
            'delivered_at' => $delivery->delivered_at?->toDateTimeString(),
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
            'city_name' => $this->formatShiplyAddressLabel($order) ?: $order->city?->name_ar,
            'total' => (float) $order->total,
            'customer_debt_balance' => (float) $order->customer_debt_balance,
            'carrier_receivable_balance' => (float) $order->carrier_receivable_balance,
            'payment_type' => $order->payment_type,
            'created_at' => $order->created_at?->toDateTimeString(),
            'created_by_name' => $order->createdByUser?->name,
            'delivery_company_id' => $order->delivery_company_id,
            'delivery_company_name' => $order->delivery_company_name
                ?: $order->deliveryCompany?->name,
            'delivery_company_code' => $order->deliveryCompany?->code
                ? strtolower((string) $order->deliveryCompany->code)
                : null,
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
            'stuckAssignedUser:id,name',
            'settlements.createdBy:id,name',
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
            'partner_type' => 'nullable|string|in:customer,seller',
            'partner_id' => 'nullable|integer|min:1',
            'partner_address_id' => 'nullable|integer|exists:partner_addresses,id',
            'customer_name' => 'nullable|string|max:255',
            'customer_phone' => 'nullable|string|max:50',
            'customer_address' => 'nullable|string|max:500',
            'city_id' => 'nullable|integer|exists:cities,id',
            'shiply_city_id' => 'nullable|integer|min:1',
            'shiply_village_id' => 'nullable|integer|min:1',
            'shiply_city_name' => 'nullable|string|max:255',
            'shiply_village_name' => 'nullable|string|max:255',
            'payment_type' => 'nullable|string|in:cash,credit,mixed,visa',
            'payment_box_id' => 'nullable|integer|exists:boxes,id',
            'payment_amount' => 'nullable|numeric|min:0',
            'delivery_company_id' => 'nullable|integer|exists:delivery_companies,id',
            'delivery_company_name' => 'nullable|string|max:255',
            'customer_delivery_fee' => 'nullable|numeric|min:0',
            'price_includes_delivery' => 'nullable|boolean',
            'shiply_quoted_delivery_fee' => 'nullable|numeric|min:0',
            'total' => 'nullable|numeric|min:0',
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
            'acknowledge_negative_stock' => 'nullable|boolean',
        ];

        $data = $request->validate($rules);

        if (! empty($data['partner_type']) && ! empty($data['partner_id'])) {
            $customerAddressProvided = array_key_exists('customer_address', $data);
            $customerPhoneProvided = array_key_exists('customer_phone', $data);
            $partnerClass = $data['partner_type'] === 'customer' ? Customer::class : \App\Models\Seller::class;
            $selectedPartner = $partnerClass::query()->find($data['partner_id']);
            if (! $selectedPartner) {
                throw ValidationException::withMessages(['partner_id' => [__('validation.exists')]]);
            }
            $data['customer_name'] = $data['customer_name'] ?? $selectedPartner->name;
            $data['customer_phone'] = $data['customer_phone'] ?? $selectedPartner->phone;
            $data['customer_address'] = $data['customer_address'] ?? $selectedPartner->address;
            if ($data['partner_type'] === 'customer') {
                $data['customer_id'] = $data['customer_id'] ?? $selectedPartner->id;
            }
            if (! empty($data['partner_address_id'])) {
                $selectedAddress = \App\Models\PartnerAddress::query()
                    ->whereKey($data['partner_address_id'])
                    ->where('addressable_type', $partnerClass)
                    ->where('addressable_id', $data['partner_id'])
                    ->first();
                if (! $selectedAddress) {
                    throw ValidationException::withMessages([
                        'partner_address_id' => ['العنوان المختار لا يتبع الزبون أو المورد المحدد.'],
                    ]);
                }
                if (! $customerAddressProvided) {
                    $data['customer_address'] = $this->normalizeStreetAddress($selectedAddress->street_address);
                }
                if (! $customerPhoneProvided && filled($selectedAddress->phone)) {
                    $data['customer_phone'] = $selectedAddress->phone;
                }
                $data['city_id'] = $data['city_id'] ?? $selectedAddress->city_id;
                $data['shiply_city_id'] = $data['shiply_city_id'] ?? $selectedAddress->shiply_city_id;
                $data['shiply_village_id'] = $data['shiply_village_id'] ?? $selectedAddress->shiply_village_id;
                $data['shiply_city_name'] = $data['shiply_city_name'] ?? $selectedAddress->shiply_city_name;
                $data['shiply_village_name'] = $data['shiply_village_name'] ?? $selectedAddress->shiply_village_name;
            }
        }

        $data = $this->enrichShiplyAddressNames($data);

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
                    'customer_name' => $data['customer_name'] ?? $customer->name,
                    'customer_phone' => $data['customer_phone'] ?? $customer->phone,
                    'customer_address' => array_key_exists('customer_address', $data)
                        ? $data['customer_address']
                        : ($order?->customer_address ?? $customer->address),
                ];
            }
        }

        return [
            'customer_id' => array_key_exists('customer_id', $data)
                ? $data['customer_id']
                : $order?->customer_id,
            'customer_name' => $data['customer_name'] ?? $order?->customer_name,
            'customer_phone' => $data['customer_phone'] ?? $order?->customer_phone,
            'customer_address' => $data['customer_address'] ?? $order?->customer_address,
        ];
    }

    private function normalizeStreetAddress(mixed $value): string
    {
        $address = trim((string) $value);

        return $address !== '' ? $address : '----';
    }

    private function addressSnapshot(array $data, array $customer, ?SalesOrder $order = null): array
    {
        if (empty($data['partner_address_id']) && $order && ! array_key_exists('customer_address', $data)) {
            return $order->address_snapshot ?? [];
        }

        $address = ! empty($data['partner_address_id'])
            ? \App\Models\PartnerAddress::query()->find($data['partner_address_id'])
            : null;

        return [
            'label' => $address?->label,
            'city_id' => $data['city_id'] ?? $address?->city_id ?? $order?->city_id,
            'shiply_city_id' => $data['shiply_city_id'] ?? $address?->shiply_city_id ?? $order?->shiply_city_id,
            'shiply_village_id' => $data['shiply_village_id'] ?? $address?->shiply_village_id ?? $order?->shiply_village_id,
            'shiply_city_name' => $data['shiply_city_name'] ?? $address?->shiply_city_name ?? $order?->shiply_city_name,
            'shiply_village_name' => $data['shiply_village_name'] ?? $address?->shiply_village_name ?? $order?->shiply_village_name,
            'street_address' => $this->normalizeStreetAddress(
                $data['customer_address'] ?? $address?->street_address ?? $customer['customer_address'] ?? null
            ),
            'phone' => $address?->phone ?? $customer['customer_phone'] ?? null,
            'latitude' => $address?->latitude,
            'longitude' => $address?->longitude,
            'delivery_notes' => $address?->delivery_notes,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{subtotal: float, discount: float, customer_delivery_fee: float, calculated_total: float, total: float}
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

        $calculatedTotal = max(0, round($subtotal + $deliveryFee - $discount, 2));
        $total = array_key_exists('total', $data)
            ? round((float) $data['total'], 2)
            : ($order !== null ? round((float) $order->total, 2) : $calculatedTotal);

        return [
            'subtotal' => round($subtotal, 2),
            'discount' => round($discount, 2),
            'customer_delivery_fee' => round($deliveryFee, 2),
            'calculated_total' => $calculatedTotal,
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

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function enrichShiplyAddressNames(array $data): array
    {
        if (empty($data['shiply_city_id']) && empty($data['shiply_village_id'])) {
            return $data;
        }

        $mode = ShiplySettings::mode();

        if (empty($data['shiply_city_name']) && ! empty($data['shiply_city_id'])) {
            $data['shiply_city_name'] = ShiplyCity::query()
                ->where('shiply_id', (int) $data['shiply_city_id'])
                ->where('mode', $mode)
                ->value('name');
        }

        if (empty($data['shiply_village_name']) && ! empty($data['shiply_village_id'])) {
            $data['shiply_village_name'] = ShiplyVillage::query()
                ->where('shiply_id', (int) $data['shiply_village_id'])
                ->where('mode', $mode)
                ->value('name');
        }

        return $data;
    }

    private function formatShiplyAddressLabel(SalesOrder $order): ?string
    {
        $city = trim((string) ($order->shiply_city_name ?? ''));
        $village = trim((string) ($order->shiply_village_name ?? ''));

        if ($city === '' && $village === '') {
            return null;
        }

        if ($city !== '' && $village !== '') {
            return $city.' — '.$village;
        }

        return $city !== '' ? $city : $village;
    }

    private function isShiplyDeliveryCompany(SalesOrder $order): bool
    {
        $code = $order->relationLoaded('deliveryCompany')
            ? $order->deliveryCompany?->code
            : $order->deliveryCompany()->value('code');

        return strtolower((string) $code) === 'shiply';
    }

    public function markStuck(User $user, int $orderId, ?string $reason = null, array $meta = []): SalesOrder
    {
        $order = SalesOrder::query()->findOrFail($orderId);
        $this->assertTransition($order, [
            SalesOrderStatus::Returned,
            SalesOrderStatus::Review,
            SalesOrderStatus::WithDelivery,
            SalesOrderStatus::PartialDelivered,
            SalesOrderStatus::PartialReturn,
        ]);

        return DB::transaction(function () use ($user, $order, $reason, $meta) {
            $from = $order->status;
            $order->update([
                'status' => SalesOrderStatus::Stuck->value,
                'stuck_previous_status' => $from,
                'stuck_type' => $meta['stuck_type'] ?? 'other',
                'stuck_reason' => $reason,
                'stuck_assigned_to' => $meta['stuck_assigned_to'] ?? $user->id,
                'stuck_follow_up_at' => $meta['stuck_follow_up_at'] ?? null,
                'stuck_resolved_at' => null,
                'notes' => trim(($order->notes ? $order->notes."\n" : '').($reason ?? 'عالق')),
                'updated_by' => $user->id,
            ]);
            $this->logStatus(
                $order,
                $from,
                SalesOrderStatus::Stuck->value,
                $reason ?? 'تعليم الطلبية كعالقة',
                $user->id
            );
            $this->notifications->notifyStatusChange(
                $order->fresh(),
                $from,
                SalesOrderStatus::Stuck->value,
                $user,
                $reason
            );

            return $order->fresh($this->detailRelations());
        });
    }

    public function resolveStuck(User $user, int $orderId, ?string $targetStatus = null, ?string $note = null): SalesOrder
    {
        $order = SalesOrder::query()->findOrFail($orderId);
        $this->assertTransition($order, [SalesOrderStatus::Stuck]);
        $target = SalesOrderStatus::tryFrom((string) ($targetStatus ?: $order->stuck_previous_status));
        $allowed = [
            SalesOrderStatus::Ready,
            SalesOrderStatus::WithDelivery,
            SalesOrderStatus::Review,
            SalesOrderStatus::PartialDelivered,
            SalesOrderStatus::PartialReturn,
            SalesOrderStatus::Returned,
            SalesOrderStatus::Canceled,
        ];

        if (! $target || ! in_array($target, $allowed, true)) {
            throw ValidationException::withMessages([
                'target_status' => ['الحالة المختارة غير مسموحة لمعالجة الطلبية العالقة.'],
            ]);
        }

        return DB::transaction(function () use ($user, $order, $target, $note) {
            $from = $order->status;
            $message = $note ?: 'تم حل مشكلة الطلبية العالقة';
            $order->update([
                'status' => $target->value,
                'stuck_resolved_at' => now(),
                'updated_by' => $user->id,
            ]);
            $this->logStatus($order, $from, $target->value, $message, $user->id);
            $this->notifications->notifyStatusChange($order->fresh(), $from, $target->value, $user, $message);

            return $order->fresh($this->detailRelations());
        });
    }

    /**
     * @param  list<int>  $orderIds
     * @return array{updated: int, failed: list<array<string, mixed>>}
     */
    public function bulkStatusAction(User $user, array $orderIds, string $action): array
    {
        $allowed = ['confirm', 'mark_ready', 'cancel'];
        if (! in_array($action, $allowed, true)) {
            throw ValidationException::withMessages([
                'action' => [__('messages.sales_order_bulk_action_invalid')],
            ]);
        }

        $updated = 0;
        $failed = [];

        foreach ($orderIds as $orderId) {
            try {
                match ($action) {
                    'confirm' => $this->confirm($user, (int) $orderId),
                    'mark_ready' => $this->markReady($user, (int) $orderId),
                    'cancel' => $this->cancel($user, (int) $orderId),
                };
                $updated++;
            } catch (ValidationException $e) {
                $failed[] = [
                    'order_id' => (int) $orderId,
                    'errors' => $e->errors(),
                ];
            }
        }

        return ['updated' => $updated, 'failed' => $failed];
    }

    private function cancelActiveShiplyParcel(SalesOrder $order): void
    {
        $delivery = $order->deliveries()
            ->whereNotNull('shiply_parcel_code')
            ->latest('id')
            ->first();

        if (! $delivery?->shiply_parcel_code) {
            return;
        }

        try {
            $this->shiplyService->cancelParcel(
                $delivery->shiply_parcel_code,
                $delivery->shiply_mode
            );
        } catch (\Throwable) {
            // Revert should proceed even if Shiply cancel fails.
        }
    }
}
