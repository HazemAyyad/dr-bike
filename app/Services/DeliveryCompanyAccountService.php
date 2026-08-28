<?php

namespace App\Services;

use App\Enums\SalesOrderStatus;
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
            ->selectRaw('delivery_company_id, COALESCE(delivery_company_name, ?) as account_name', ['شركة توصيل غير محددة'])
            ->selectRaw('COUNT(CASE WHEN carrier_receivable_balance > 0 THEN 1 END) as outstanding_orders_count')
            ->selectRaw('COALESCE(SUM(carrier_receivable_balance), 0) as outstanding_balance')
            ->whereNotNull('delivery_company_id')
            ->where(function ($query) {
                $query->where('carrier_receivable_balance', '>', 0)
                    ->orWhereHas('settlements', fn ($settlements) => $settlements->where('source', 'carrier'));
            })
            ->groupBy('delivery_company_id', 'delivery_company_name')
            ->orderByDesc('outstanding_balance')
            ->get()
            ->map(fn ($row) => [
                'delivery_company_id' => (int) $row->delivery_company_id,
                'delivery_company_name' => (string) $row->account_name,
                'outstanding_orders_count' => (int) $row->outstanding_orders_count,
                'outstanding_balance' => (float) $row->outstanding_balance,
            ])->values()->all();
    }

    public function account(int $companyId, string $companyName): array
    {
        $ordersQuery = $this->accountOrdersQuery($companyId, $companyName);
        $orders = (clone $ordersQuery)
            ->with(['settlements' => fn ($query) => $query->where('source', 'carrier')->latest('id')])
            ->orderBy('created_at')
            ->get();

        $batches = DeliveryCompanySettlementBatch::query()
            ->where('delivery_company_id', $companyId)
            ->where('delivery_company_name', $companyName)
            ->with(['box:id,name', 'createdBy:id,name', 'settlements.order:id,serial_number'])
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn ($batch) => [
                'id' => $batch->id,
                'amount' => (float) $batch->amount,
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
                'carrier_receivable_balance' => (float) $order->carrier_receivable_balance,
                'settled_amount' => (float) $order->settlements->sum('amount'),
                'created_at' => $order->created_at?->toIso8601String(),
                'updated_at' => $order->updated_at?->toIso8601String(),
            ])->values(),
            'batches' => $batches,
        ];
    }

    public function settleBatch(User $user, array $payload): DeliveryCompanySettlementBatch
    {
        $companyId = (int) $payload['delivery_company_id'];
        $companyName = trim((string) $payload['delivery_company_name']);
        $allocations = collect($payload['allocations'])
            ->map(fn ($row) => [
                'order_id' => (int) $row['order_id'],
                'amount' => round((float) $row['amount'], 2),
            ]);
        if ($allocations->pluck('order_id')->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages(['allocations' => ['لا يمكن تكرار الطلبية في نفس التسوية.']]);
        }

        $idempotencyKey = (string) $payload['idempotency_key'];
        $existing = DeliveryCompanySettlementBatch::query()
            ->where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return $existing->load('settlements');
        }

        return DB::transaction(function () use ($user, $payload, $companyId, $companyName, $allocations, $idempotencyKey) {
            $orders = SalesOrder::query()
                ->whereIn('id', $allocations->pluck('order_id'))
                ->lockForUpdate()
                ->get()->keyBy('id');

            foreach ($allocations as $allocation) {
                $order = $orders->get($allocation['order_id']);
                if (! $order || (int) $order->delivery_company_id !== $companyId ||
                    (string) $order->delivery_company_name !== $companyName) {
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
                'amount' => round((float) $allocations->sum('amount'), 2),
                'orders_count' => $allocations->count(),
                'idempotency_key' => $idempotencyKey,
                'notes' => $payload['notes'] ?? null,
                'created_by' => $user->id,
            ]);

            foreach ($allocations as $allocation) {
                $settlementKey = $idempotencyKey.'-'.$allocation['order_id'];
                $this->fulfillment->settleDelivery($user, $allocation['order_id'], [
                    'delivery_settled_amount' => $allocation['amount'],
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

    private function accountOrdersQuery(int $companyId, string $companyName)
    {
        return SalesOrder::query()
            ->where('delivery_company_id', $companyId)
            ->where('delivery_company_name', $companyName)
            ->where(function ($query) {
                $query->where('carrier_receivable_balance', '>', 0)
                    ->orWhereHas('settlements', fn ($settlements) => $settlements->where('source', 'carrier'));
            });
    }
}
