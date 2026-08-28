<?php

namespace App\Services;

use App\Enums\SalesOrderStatus;
use App\Http\Controllers\API\BoxLogs;
use App\Models\Box;
use App\Models\DeliveryCompany;
use App\Models\InstantSale;
use App\Models\SalesOrder;
use App\Models\SalesOrderDelivery;
use App\Models\SalesOrderItem;
use App\Models\SalesOrderMedia;
use App\Models\SalesOrderSettlement;
use App\Models\SalesOrderStatusLog;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Support\ShiplySettings;
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
        protected ShiplyService $shiplyService,
        protected SalesOrderShiplyTrackingService $shiplyTracking,
        protected SalesOrderMediaRequirementService $mediaRequirements,
        protected SalesOrdersDailyBoxService $ordersDailyBoxes,
    ) {}

    public function handoverToDelivery(User $user, int $orderId, array $payload): SalesOrder
    {
        $order = SalesOrder::query()->with(['items', 'packages', 'deliveryCompany', 'media'])->findOrFail($orderId);
        $this->assertTransition($order, [SalesOrderStatus::Ready]);

        $this->mediaRequirements->assertCategoriesPresent(
            $order,
            $this->mediaRequirements->requiredBeforeHandover()
        );

        $data = validator($payload, [
            'delivery_company_id' => 'nullable|integer|exists:delivery_companies,id',
            'delivery_company_name' => 'nullable|string|max:255',
            'tracking_number' => 'nullable|string|max:100',
            'carrier_contact_name' => 'nullable|string|max:255',
            'carrier_contact_phone' => 'nullable|string|max:50',
            'carrier_office_name' => 'nullable|string|max:255',
            'carrier_vehicle_number' => 'nullable|string|max:50',
            'carrier_delivery_cost' => 'nullable|numeric|min:0',
        ])->validate();

        if (empty($data['delivery_company_id']) && empty($data['delivery_company_name'])) {
            throw ValidationException::withMessages([
                'delivery_company_id' => [__('messages.sales_order_delivery_company_required')],
            ]);
        }

        $deliveryCompanyId = $data['delivery_company_id'] ?? $order->delivery_company_id;
        $companyCode = $this->resolveDeliveryCompanyCode($deliveryCompanyId);
        $isShiply = $companyCode === 'shiply';

        $this->assertHandoverRecipient($order, $isShiply);
        $this->assertManualCarrierDetails($data, $companyCode);
        $shiplyMode = ShiplySettings::mode();
        $employeeEmail = trim((string) $user->email);

        if ($isShiply) {
            if (empty($data['tracking_number'])) {
                $data['tracking_number'] = null;
            }
        }

        $parcelCode = null;
        $qrCode = null;
        if ($isShiply) {
            $order = $order->fresh(['items', 'media']);
            $parcel = $this->shiplyService->createAndSubmitParcel($order, $shiplyMode);
            $parcelCode = $parcel['parcel_code'];
            $qrCode = $parcel['qr_code'] ?? null;
            $data['tracking_number'] = $parcelCode;
        }

        return DB::transaction(function () use ($user, $order, $data, $isShiply, $shiplyMode, $employeeEmail, $parcelCode, $qrCode, $deliveryCompanyId, $companyCode) {
            $companyName = $data['delivery_company_name'] ?? null;
            if (! $companyName && $companyCode === 'office') {
                $companyName = trim((string) ($data['carrier_office_name'] ?? '')) ?: null;
            }
            if (! $companyName && $companyCode === 'taxi') {
                $taxiDetails = array_filter([
                    trim((string) ($data['carrier_contact_name'] ?? '')),
                    trim((string) ($data['carrier_vehicle_number'] ?? '')),
                ]);
                $companyName = $taxiDetails ? 'تكسي — '.implode(' — ', $taxiDetails) : null;
            }
            if ($deliveryCompanyId && ! $companyName) {
                $companyName = DeliveryCompany::query()->find($deliveryCompanyId)?->name;
            }

            SalesOrderDelivery::create([
                'sales_order_id' => $order->id,
                'delivery_company_id' => $deliveryCompanyId,
                'delivery_company_name' => $companyName,
                'tracking_number' => $data['tracking_number'] ?? null,
                'carrier_contact_name' => $data['carrier_contact_name'] ?? null,
                'carrier_contact_phone' => $data['carrier_contact_phone'] ?? null,
                'carrier_office_name' => $data['carrier_office_name'] ?? null,
                'carrier_vehicle_number' => $data['carrier_vehicle_number'] ?? null,
                'external_reference' => $parcelCode,
                'shiply_parcel_code' => $parcelCode,
                'shiply_qr_code' => $isShiply ? $qrCode : null,
                'shiply_employee_email' => $isShiply ? $employeeEmail : null,
                'shiply_mode' => $isShiply ? $shiplyMode : null,
                'handed_over_by_user_id' => $user->id,
                'handed_over_at' => now(),
            ]);

            $order->packages()->update(['status' => 'with_delivery']);

            $from = $order->status;
            $order->update([
                'status' => SalesOrderStatus::WithDelivery->value,
                'delivery_company_id' => $deliveryCompanyId,
                'delivery_company_name' => $companyName ?? $order->delivery_company_name,
                'carrier_delivery_cost' => $data['carrier_delivery_cost'] ?? $order->carrier_delivery_cost,
                'updated_by' => $user->id,
            ]);

            if (! empty($data['tracking_number'])) {
                $order->packages()->update(['tracking_number' => $data['tracking_number']]);
            }

            $note = $isShiply
                ? 'تسليم لشبلي — '.$parcelCode
                : 'تسليم لشركة التوصيل';
            $this->logStatus($order, $from, SalesOrderStatus::WithDelivery->value, $note, $user->id);

            $freshOrder = $order->fresh();
            if ($isShiply && $parcelCode) {
                $this->shiplyTracking->recordHandoverSubmitted($freshOrder, $parcelCode, $shiplyMode);
                $submittedStatus = (int) config('shiply.parcel_status.submitted', 2);
                $this->notifications->notifyShiplyStatusChange(
                    $freshOrder,
                    $submittedStatus,
                    $parcelCode,
                    null,
                    $user,
                );
            } else {
                $this->notifications->notifyStatusChange(
                    $freshOrder,
                    $from,
                    SalesOrderStatus::WithDelivery->value,
                    $user,
                    $note
                );
            }

            return $freshOrder;
        });
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function markDeliveredFromShiply(User $user, int $orderId, array $meta = []): SalesOrder
    {
        $order = SalesOrder::query()->with(['items'])->findOrFail($orderId);

        if ($order->statusEnum() === SalesOrderStatus::Delivered) {
            return $order;
        }

        $this->assertTransition($order, [SalesOrderStatus::WithDelivery]);

        $note = trim((string) ($meta['note'] ?? ''));
        $logNote = $note !== '' ? $note : 'تم التوصيل تلقائياً من Shiply';
        $globalSession = $this->sessionService->findGlobalOpenSession();
        $financialActor = $globalSession?->allowsSales() === true
            ? ($globalSession->user ?? $user)
            : $user;

        return DB::transaction(function () use ($financialActor, $order, $logNote) {
            $financials = $this->postDeliveryFinancials($order, $financialActor, (float) $order->total, []);

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
                'financial_posted_at' => now(),
                'payment_box_id' => $financials['payment_box_id'],
                'payment_amount' => $financials['paid_amount'],
                'sales_daily_session_id' => $financials['sales_daily_session_id'],
                'customer_debt_balance' => $financials['customer_debt_balance'],
                'carrier_receivable_balance' => $financials['carrier_receivable_balance'],
                'delivery_settled_at' => ($financials['customer_debt_balance'] <= 0 && $financials['carrier_receivable_balance'] <= 0) ? now() : null,
                'delivery_settled_amount' => ($financials['customer_debt_balance'] <= 0 && $financials['carrier_receivable_balance'] <= 0) ? $financials['paid_amount'] : 0,
                'updated_by' => $financialActor->id,
            ]);

            $this->logStatus($order, $from, SalesOrderStatus::Delivered->value, $logNote, $financialActor->id);

            return $order->fresh();
        });
    }

    public function markCanceledFromShiply(User $user, int $orderId, ?string $note = null): SalesOrder
    {
        $order = SalesOrder::query()->findOrFail($orderId);

        if (in_array($order->statusEnum(), [
            SalesOrderStatus::Canceled,
            SalesOrderStatus::Returned,
            SalesOrderStatus::Archived,
        ], true)) {
            return $order;
        }

        if ($order->statusEnum() === SalesOrderStatus::Delivered) {
            throw ValidationException::withMessages([
                'order' => [__('messages.sales_order_invalid_status_transition')],
            ]);
        }

        return DB::transaction(function () use ($user, $order, $note) {
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
            $order->packages()->update(['status' => 'canceled']);

            $logNote = $note ?? 'تم إلغاء الطرد في Shiply';
            $this->logStatus($order, $from, SalesOrderStatus::Canceled->value, $logNote, $user->id);
            $this->notifications->notifyStatusChange(
                $order->fresh(),
                $from,
                SalesOrderStatus::Canceled->value,
                $user,
                $logNote,
            );

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
            'customer_debt_amount' => 'nullable|numeric|min:0',
        ])->validate();

        if (! empty($data['payment_amount'])) {
            $order->payment_amount = (float) $data['payment_amount'];
        }

        return DB::transaction(function () use ($user, $order, $data) {
            $financials = $this->postDeliveryFinancials($order, $user, (float) $order->total, $data);

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
                'financial_posted_at' => now(),
                'payment_box_id' => $financials['payment_box_id'],
                'payment_amount' => $financials['paid_amount'],
                'sales_daily_session_id' => $financials['sales_daily_session_id'],
                'customer_debt_balance' => $financials['customer_debt_balance'],
                'carrier_receivable_balance' => $financials['carrier_receivable_balance'],
                'delivery_settled_at' => ($financials['customer_debt_balance'] <= 0 && $financials['carrier_receivable_balance'] <= 0) ? now() : null,
                'delivery_settled_amount' => ($financials['customer_debt_balance'] <= 0 && $financials['carrier_receivable_balance'] <= 0) ? $financials['paid_amount'] : 0,
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

        $requestedAmount = (float) ($payload['delivery_settled_amount'] ?? 0);
        if ($requestedAmount <= 0
            && (float) $order->customer_debt_balance <= 0
            && (float) $order->carrier_receivable_balance <= 0) {
            $order->update([
                'delivery_settled_at' => $order->delivery_settled_at ?? now(),
                'delivery_settled_amount' => max(
                    (float) $order->delivery_settled_amount,
                    (float) $order->payment_amount
                ),
                'updated_by' => $user->id,
            ]);

            return $order->fresh();
        }

        $data = validator($payload, [
            'delivery_settled_amount' => 'required|numeric|gt:0',
            'payment_box_id' => 'nullable|integer|exists:boxes,id',
            'source' => 'nullable|string|in:carrier,customer_debt',
            'idempotency_key' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:2000',
        ])->validate();

        $amount = (float) $data['delivery_settled_amount'];
        $source = $data['source'] ?? 'carrier';
        $resolvedBox = $this->ordersDailyBoxes->resolve(
            $user,
            isset($data['payment_box_id']) ? (int) $data['payment_box_id'] : null
        );

        return DB::transaction(function () use ($user, $order, $amount, $source, $resolvedBox, $data) {
            $locked = SalesOrder::query()->lockForUpdate()->findOrFail($order->id);
            if (! empty($data['idempotency_key'])) {
                $existing = SalesOrderSettlement::query()
                    ->where('idempotency_key', $data['idempotency_key'])->first();
                if ($existing) {
                    return $locked->fresh();
                }
            }

            $customerBefore = (float) $locked->customer_debt_balance;
            $carrierBefore = (float) $locked->carrier_receivable_balance;
            $available = $source === 'customer_debt' ? $customerBefore : $carrierBefore;
            if ($amount > round($available, 2)) {
                throw ValidationException::withMessages([
                    'delivery_settled_amount' => ['مبلغ التسوية أكبر من الرصيد المستحق ('.number_format($available, 2).').'],
                ]);
            }

            $customerAfter = $source === 'customer_debt' ? round($customerBefore - $amount, 2) : $customerBefore;
            $carrierAfter = $source === 'carrier' ? round($carrierBefore - $amount, 2) : $carrierBefore;
            $session = $this->sessionService->assertCanCreateSale($user);
            $box = Box::lockForUpdate()->findOrFail($resolvedBox['id']);
            $box->total = round((float) $box->total + $amount, 2);
            $box->save();

            BoxLogs::createBoxLog(
                $box,
                $source === 'carrier' ? 'تسوية شركة توصيل — '.$locked->serial_number : 'تحصيل دين طلبية — '.$locked->serial_number,
                'add', $amount, 'طلبية #'.$locked->id.' — '.$locked->serial_number
            );

            SalesOrderSettlement::create([
                'sales_order_id' => $locked->id,
                'sales_daily_session_id' => $session->id,
                'box_id' => $box->id,
                'source' => $source,
                'amount' => $amount,
                'customer_debt_before' => $customerBefore,
                'customer_debt_after' => $customerAfter,
                'carrier_receivable_before' => $carrierBefore,
                'carrier_receivable_after' => $carrierAfter,
                'idempotency_key' => $data['idempotency_key'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
            ]);

            if ($source === 'customer_debt') {
                $this->debtLedgerService->syncSalesOrderToLedger($locked, (float) $locked->total, (float) $locked->total - $customerAfter);
            }

            $totalSettled = round((float) $locked->settlements()->sum('amount'), 2);
            $locked->update([
                'customer_debt_balance' => $customerAfter,
                'carrier_receivable_balance' => $carrierAfter,
                'delivery_settled_at' => ($customerAfter <= 0 && $carrierAfter <= 0) ? now() : null,
                'delivery_settled_amount' => $totalSettled,
                'delivery_settled_box_id' => $box->id,
                'updated_by' => $user->id,
            ]);

            $this->logStatus(
                $locked,
                $locked->status,
                SalesOrderStatus::Delivered->value,
                ($source === 'carrier' ? 'تسوية جزئية/كاملة مع شركة التوصيل' : 'تحصيل دين طلبية').' — '.$amount,
                $user->id
            );

            return $locked->fresh();
        });
    }

    public function archive(User $user, int $orderId): SalesOrder
    {
        $order = SalesOrder::query()->findOrFail($orderId);
        $this->assertTransition($order, [SalesOrderStatus::Delivered]);

        if ((float) $order->customer_debt_balance > 0 || (float) $order->carrier_receivable_balance > 0) {
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

    public function markReturned(
        User $user,
        int $orderId,
        ?string $note = null,
        bool $skipNotification = false,
    ): SalesOrder
    {
        $order = SalesOrder::query()->with('deliveryCompany')->findOrFail($orderId);
        $this->assertTransition($order, [
            SalesOrderStatus::WithDelivery,
            SalesOrderStatus::PartialReturn,
            SalesOrderStatus::PartialDelivered,
            SalesOrderStatus::Review,
        ]);

        $latestDelivery = SalesOrderDelivery::query()
            ->where('sales_order_id', $order->id)
            ->latest('id')
            ->first();

        if ($latestDelivery?->shiply_parcel_code) {
            try {
                $this->shiplyService->cancelParcel(
                    $latestDelivery->shiply_parcel_code,
                    $latestDelivery->shiply_mode
                );
            } catch (\Throwable) {
                // Local return should still proceed even if Shiply cancel fails.
            }
        }

        return DB::transaction(function () use ($user, $order, $note, $skipNotification) {
            if ($order->stock_deducted_at) {
                $this->stockService->restoreDispatchedOrder($order, (int) $user->id);
            } else {
                $this->stockService->releaseOrder($order);
            }

            $from = $order->status;
            $order->update([
                'status' => SalesOrderStatus::Returned->value,
                'stock_deducted_at' => null,
                'updated_by' => $user->id,
            ]);
            $order->packages()->update(['status' => 'returned']);

            $this->logStatus($order, $from, SalesOrderStatus::Returned->value, $note ?? 'راجع من شركة التوصيل', $user->id);
            if (! $skipNotification) {
                $this->notifications->notifyStatusChange($order->fresh(), $from, SalesOrderStatus::Returned->value, $user, $note);
            }

            return $order->fresh();
        });
    }

    public function uploadMedia(User $user, int $orderId, array $files, ?string $category = null): SalesOrder
    {
        $order = SalesOrder::query()->findOrFail($orderId);

        if ($order->statusEnum() === SalesOrderStatus::Archived) {
            throw ValidationException::withMessages([
                'order' => [__('messages.sales_order_not_editable')],
            ]);
        }

        $category = \App\Support\SalesOrderMediaCategory::normalize($category);

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

        DB::transaction(function () use ($user, $order, $files, $category) {
            foreach ($files as $file) {
                if (! $file instanceof UploadedFile) {
                    continue;
                }

                $path = $file->store('sales_orders/'.$order->id, 'public');
                $mime = $file->getMimeType() ?? '';
                $type = str_starts_with($mime, 'video/') ? 'video' : 'image';

                $attributes = [
                    'sales_order_id' => $order->id,
                    'status_at_upload' => $order->status,
                    'type' => $type,
                    'path' => $path,
                    'mime' => $mime,
                    'size_bytes' => $file->getSize(),
                    'uploaded_by' => $user->id,
                ];

                if (Schema::hasColumn('sales_order_media', 'category')) {
                    $attributes['category'] = $category;
                }

                SalesOrderMedia::create($attributes);
            }
        });

        return $order->fresh();
    }

    /**
     * Post cash box and debt ledger entries when a sales order is delivered.
     *
     * @param  array<string, mixed>  $payload
     * @return array{paid_amount: float, payment_box_id: int|null, sales_daily_session_id: int|null, customer_debt_balance: float, carrier_receivable_balance: float}
     */
    public function postDeliveryFinancials(
        SalesOrder $order,
        User $user,
        float $recognizedTotal,
        array $payload = []
    ): array {
        if ($order->financial_posted_at) {
            return [
                'paid_amount' => (float) $order->payment_amount,
                'payment_box_id' => $order->payment_box_id,
                'sales_daily_session_id' => $order->sales_daily_session_id,
                'customer_debt_balance' => (float) $order->customer_debt_balance,
                'carrier_receivable_balance' => (float) $order->carrier_receivable_balance,
            ];
        }

        $session = $this->sessionService->assertCanCreateSale($user);

        $paidAmount = $this->resolvePaidAmountForTotal($order, $recognizedTotal, $payload);
        $companyCode = $this->resolveDeliveryCompanyCode($order->delivery_company_id);
        $isExternalCarrier = $companyCode !== null && ! in_array($companyCode, ['doctor_bike', 'self', 'pickup'], true);
        if ($isExternalCarrier && ! array_key_exists('payment_amount', $payload)) {
            $paidAmount = min((float) $order->payment_amount, $recognizedTotal);
        }
        $paymentBox = $this->resolvePaymentBox($user, $paidAmount, $payload);

        if ($paidAmount > 0 && $paymentBox) {
            $box = Box::lockForUpdate()->findOrFail($paymentBox['id']);
            $box->total = round((float) $box->total + $paidAmount, 2);
            $box->save();

            BoxLogs::createBoxLog(
                $box,
                'قبض — طلبية '.($order->serial_number ?? '#'.$order->id),
                'add',
                $paidAmount,
                'طلبية #'.$order->id.' — '.($order->serial_number ?? '')
            );
        }

        if ($paymentBox) {
            $order->payment_box_id = $paymentBox['id'];
        }

        $remaining = round(max(0, $recognizedTotal - $paidAmount), 2);
        $requestedCustomerDebt = min((float) ($payload['customer_debt_amount'] ?? 0), $remaining);
        $customerDebt = $isExternalCarrier ? round($requestedCustomerDebt, 2) : $remaining;
        $carrierReceivable = $isExternalCarrier
            ? round(max(0, $remaining - $customerDebt), 2)
            : 0.0;
        $this->debtLedgerService->syncSalesOrderToLedger(
            $order,
            $recognizedTotal,
            round($recognizedTotal - $customerDebt, 2)
        );

        return [
            'paid_amount' => $paidAmount,
            'payment_box_id' => $paymentBox['id'] ?? $order->payment_box_id,
            'sales_daily_session_id' => $session->id,
            'customer_debt_balance' => $customerDebt,
            'carrier_receivable_balance' => $carrierReceivable,
        ];
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

        $mainAttributes = array_merge($buyer, [
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
        ]);

        if (Schema::hasColumn('instant_sales', 'sales_order_id')) {
            $mainAttributes['sales_order_id'] = $order->id;
        }

        $main = InstantSale::create($mainAttributes);

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
        $session = null,
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

    private function isShiplyCompany(?int $deliveryCompanyId): bool
    {
        return $this->resolveDeliveryCompanyCode($deliveryCompanyId) === 'shiply';
    }

    private function resolveDeliveryCompanyCode(?int $deliveryCompanyId): ?string
    {
        if (! $deliveryCompanyId) {
            return null;
        }

        $code = DeliveryCompany::query()->where('id', $deliveryCompanyId)->value('code');

        return $code !== null ? strtolower(trim((string) $code)) : null;
    }

    private function assertHandoverRecipient(SalesOrder $order, bool $isShiply): void
    {
        $name = trim((string) $order->customer_name);
        $phone = trim((string) $order->customer_phone);

        if ($name === '') {
            throw ValidationException::withMessages([
                'customer_name' => [__('messages.sales_order_handover_customer_required')],
            ]);
        }

        if ($phone === '') {
            throw ValidationException::withMessages([
                'customer_phone' => [__('messages.sales_order_handover_phone_required')],
            ]);
        }

        if ($isShiply) {
            if (! $order->shiply_city_id) {
                throw ValidationException::withMessages([
                    'shiply_city_id' => [__('messages.shiply_city_required')],
                ]);
            }
            if (! $order->shiply_village_id) {
                throw ValidationException::withMessages([
                    'shiply_village_id' => [__('messages.shiply_village_required')],
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertManualCarrierDetails(array $data, ?string $companyCode): void
    {
        if ($companyCode === 'taxi') {
            $errors = [];
            if (trim((string) ($data['carrier_contact_name'] ?? '')) === '') {
                $errors['carrier_contact_name'] = [__('messages.sales_order_taxi_driver_required')];
            }
            if (trim((string) ($data['carrier_vehicle_number'] ?? $data['tracking_number'] ?? '')) === '') {
                $errors['carrier_vehicle_number'] = [__('messages.sales_order_office_vehicle_required')];
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            return;
        }

        if ($companyCode === 'shiply' || $companyCode === null) {
            return;
        }

        $errors = [];
        if (trim((string) ($data['carrier_office_name'] ?? $data['carrier_contact_name'] ?? '')) === '') {
            $errors['carrier_office_name'] = [__('messages.sales_order_office_name_required')];
        }
        if (trim((string) ($data['carrier_vehicle_number'] ?? '')) === '') {
            $errors['carrier_vehicle_number'] = [__('messages.sales_order_office_vehicle_required')];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
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

        return $this->ordersDailyBoxes->resolve(
            $user,
            ! empty($payload['payment_box_id']) ? (int) $payload['payment_box_id'] : null
        );
    }

    private function resolveSalesOrdersBox(): ?Box
    {
        $type = (string) config('sales_orders.payment_box.type', 'sales_orders');
        $name = (string) config('sales_orders.payment_box.name', 'صندوق الطلبيات');
        $currency = (string) config('sales_orders.payment_box.currency', 'شيكل');

        $box = Box::query()
            ->where('type', $type)
            ->orWhere('name', $name)
            ->first();

        if ($box) {
            return $box;
        }

        return Box::query()->create([
            'name' => $name,
            'type' => $type,
            'total' => 0,
            'is_shown' => 1,
            'currency' => $currency,
        ]);
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
