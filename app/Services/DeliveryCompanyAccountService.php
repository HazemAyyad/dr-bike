<?php

namespace App\Services;

use App\Enums\SalesOrderStatus;
use App\Models\DeliveryCompany;
use App\Models\DeliveryCompanySettlementBatch;
use App\Models\SalesOrder;
use App\Models\SalesOrderSettlement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeliveryCompanyAccountService
{
    public function __construct(
        protected SalesOrderFulfillmentService $fulfillment,
    ) {}

    public function accounts(): array
    {
        return SalesOrder::query()
            ->select('delivery_company_id')
            ->selectRaw('COUNT(CASE WHEN carrier_receivable_balance > 0 THEN 1 END) as outstanding_orders_count')
            ->selectRaw('COALESCE(SUM(carrier_receivable_balance), 0) as outstanding_balance')
            ->with('deliveryCompany:id,name')
            ->whereNotNull('delivery_company_id')
            ->where(function ($query) {
                $query->where('carrier_receivable_balance', '>', 0)
                    ->orWhereHas('settlements', fn ($settlements) => $settlements->where('source', 'carrier'));
            })
            ->groupBy('delivery_company_id')
            ->orderByDesc('outstanding_balance')
            ->get()
            ->map(fn ($row) => [
                'delivery_company_id' => (int) $row->delivery_company_id,
                'delivery_company_name' => (string) ($row->deliveryCompany?->name ?: 'شركة توصيل غير محددة'),
                'outstanding_orders_count' => (int) $row->outstanding_orders_count,
                'outstanding_balance' => (float) $row->outstanding_balance,
            ])->values()->all();
    }

    public function account(int $companyId, string $companyName): array
    {
        $companyName = DeliveryCompany::query()->whereKey($companyId)->value('name')
            ?: $companyName;
        $ordersQuery = $this->accountOrdersQuery($companyId);
        $orders = (clone $ordersQuery)
            ->with(['settlements' => fn ($query) => $query->where('source', 'carrier')->latest('id')])
            ->orderBy('created_at')
            ->get();

        $batches = DeliveryCompanySettlementBatch::query()
            ->where('delivery_company_id', $companyId)
            ->with(['box:id,name', 'createdBy:id,name', 'settlements.order:id,serial_number'])
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn ($batch) => [
                'id' => $batch->id,
                'amount' => (float) $batch->amount,
                'cash_amount' => $batch->cash_amount !== null ? (float) $batch->cash_amount : (float) $batch->amount,
                'carrier_fee' => (float) $batch->carrier_fee,
                'orders_count' => (int) $batch->orders_count,
                'box_id' => $batch->box_id,
                'box_name' => $batch->box?->name,
                'notes' => $batch->notes,
                'created_by' => $batch->createdBy?->name,
                'created_at' => $batch->created_at?->toIso8601String(),
                'allocations' => $batch->settlements->map(fn ($settlement) => [
                    'order_id' => $settlement->sales_order_id,
                    'serial_number' => $settlement->order?->serial_number,
                    'amount' => (float) $settlement->amount,
                ])->values(),
            ])->values();

        return [
            'delivery_company_id' => $companyId,
            'delivery_company_name' => $companyName,
            'outstanding_balance' => round((float) $orders->sum('carrier_receivable_balance'), 2),
            'outstanding_orders_count' => $orders->where('carrier_receivable_balance', '>', 0)->count(),
            'orders' => $orders->map(fn (SalesOrder $order) => [
                'id' => $order->id,
                'serial_number' => $order->serial_number,
                'customer_name' => $order->customer_name,
                'status' => $order->status,
                'total' => (float) $order->total,
                'customer_delivery_fee' => (float) $order->customer_delivery_fee,
                'carrier_delivery_cost' => $order->carrier_delivery_cost !== null
                    ? (float) $order->carrier_delivery_cost
                    : null,
                'carrier_receivable_balance' => (float) $order->carrier_receivable_balance,
                'settled_amount' => (float) $order->settlements->sum('amount'),
                'settled_cash_amount' => (float) $order->settlements->sum(
                    fn (SalesOrderSettlement $settlement) => $settlement->cash_amount ?? $settlement->amount
                ),
                'settled_carrier_fee' => (float) $order->settlements->sum('carrier_fee'),
                'created_at' => $order->created_at?->toIso8601String(),
                'updated_at' => $order->updated_at?->toIso8601String(),
            ])->values(),
            'batches' => $batches,
        ];
    }

    public function settleBatch(User $user, array $payload): DeliveryCompanySettlementBatch
    {
        $companyId = (int) $payload['delivery_company_id'];
        $companyName = (string) DeliveryCompany::query()
            ->whereKey($companyId)
            ->value('name');
        $allocations = collect($payload['allocations'])
            ->map(fn ($row) => [
                'order_id' => (int) $row['order_id'],
                'amount' => round((float) $row['amount'], 2),
            ]);
        if ($allocations->pluck('order_id')->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages(['allocations' => ['لا يمكن تكرار الطلبية في نفس التسوية.']]);
        }

        $idempotencyKey = (string) $payload['idempotency_key'];
        $grossAmount = round((float) $allocations->sum('amount'), 2);
        $carrierFee = round((float) ($payload['carrier_fee'] ?? 0), 2);
        if ($carrierFee > $grossAmount) {
            throw ValidationException::withMessages([
                'carrier_fee' => ['أجرة شركة التوصيل لا يمكن أن تتجاوز إجمالي مبلغ التسوية.'],
            ]);
        }
        $existing = DeliveryCompanySettlementBatch::query()
            ->where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return $existing->load('settlements');
        }

        return DB::transaction(function () use ($user, $payload, $companyId, $companyName, $allocations, $idempotencyKey, $grossAmount, $carrierFee) {
            $orders = SalesOrder::query()
                ->whereIn('id', $allocations->pluck('order_id'))
                ->lockForUpdate()
                ->get()->keyBy('id');

            foreach ($allocations as $allocation) {
                $order = $orders->get($allocation['order_id']);
                if (! $order || (int) $order->delivery_company_id !== $companyId) {
                    throw ValidationException::withMessages([
                        'allocations' => ['إحدى الطلبيات لا تتبع حساب شركة التوصيل المختارة.'],
                    ]);
                }
                if ($order->status !== SalesOrderStatus::Delivered->value) {
                    throw ValidationException::withMessages([
                        'allocations' => ['لا يمكن تسوية طلبية غير مسلّمة: '.($order->serial_number ?? $order->id)],
                    ]);
                }
                if ($allocation['amount'] <= 0 || $allocation['amount'] > round((float) $order->carrier_receivable_balance, 2)) {
                    throw ValidationException::withMessages([
                        'allocations' => ['مبلغ تسوية الطلبية '.($order->serial_number ?? $order->id).' غير صالح.'],
                    ]);
                }
            }

            $batch = DeliveryCompanySettlementBatch::create([
                'delivery_company_id' => $companyId,
                'delivery_company_name' => $companyName,
                'amount' => $grossAmount,
                'cash_amount' => round($grossAmount - $carrierFee, 2),
                'carrier_fee' => $carrierFee,
                'orders_count' => $allocations->count(),
                'idempotency_key' => $idempotencyKey,
                'notes' => $payload['notes'] ?? null,
                'created_by' => $user->id,
            ]);

            $remainingFee = $carrierFee;
            foreach ($allocations as $allocation) {
                $settlementKey = $idempotencyKey.'-'.$allocation['order_id'];
                $allocatedFee = round(min($allocation['amount'], $remainingFee), 2);
                $remainingFee = round($remainingFee - $allocatedFee, 2);
                $this->fulfillment->settleDelivery($user, $allocation['order_id'], [
                    'delivery_settled_amount' => $allocation['amount'],
                    'carrier_fee' => $allocatedFee,
                    'source' => 'carrier',
                    'payment_box_id' => $payload['payment_box_id'] ?? null,
                    'idempotency_key' => $settlementKey,
                    'notes' => $payload['notes'] ?? 'تسوية جماعية مع '.$companyName,
                ]);
                $settlement = SalesOrderSettlement::query()
                    ->where('idempotency_key', $settlementKey)->firstOrFail();
                $settlement->update(['delivery_company_settlement_batch_id' => $batch->id]);
                if (! $batch->box_id) {
                    $batch->update([
                        'box_id' => $settlement->box_id,
                        'sales_daily_session_id' => $settlement->sales_daily_session_id,
                    ]);
                }
            }

            return $batch->fresh(['settlements.order:id,serial_number', 'box:id,name', 'createdBy:id,name']);
        });
    }

    private function accountOrdersQuery(int $companyId)
    {
        return SalesOrder::query()
            ->where('delivery_company_id', $companyId)
            ->where(function ($query) {
                $query->where('carrier_receivable_balance', '>', 0)
                    ->orWhereHas('settlements', fn ($settlements) => $settlements->where('source', 'carrier'));
            });
    }
}
