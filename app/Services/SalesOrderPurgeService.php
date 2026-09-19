<?php

namespace App\Services;

use App\Models\Box;
use App\Models\BoxLog;
use App\Models\DebtTransaction;
use App\Models\DeliveryCompanySettlementBatch;
use App\Models\Expense;
use App\Models\Product;
use App\Models\ProductStockMovement;
use App\Models\SalesOrder;
use App\Models\SalesOrderPurgeBackup;
use App\Models\SalesOrderSettlement;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SalesOrderPurgeService
{
    public const MODE_WITH_EFFECTS = 'with_effects';

    public const MODE_ORDERS_ONLY_RESET = 'orders_only_reset';

    public function __construct(
        private readonly ProductStockService $stockService,
        private readonly InventoryCostingService $costingService,
        private readonly DebtLedgerService $debtLedgerService,
    ) {}

    /** @return array<string, mixed> */
    public function preview(
        CarbonInterface $cutoff,
        ?int $maxOrderId = null,
        string $mode = self::MODE_WITH_EFFECTS
    ): array {
        $query = $this->purgeQuery($cutoff, $maxOrderId);
        $orders = (clone $query)->get(['id', 'serial_number', 'created_at']);
        $orderIds = $orders->pluck('id');
        $maxId = $maxOrderId ?? ($orderIds->max() ? (int) $orderIds->max() : 0);
        $statuses = (clone $query)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count) => (int) $count)
            ->all();

        $settlements = SalesOrderSettlement::query()
            ->whereIn('sales_order_id', $orderIds)
            ->get(['sales_order_id', 'box_id', 'amount', 'cash_amount']);

        $cashByCurrency = [];
        $boxCurrencies = [];
        $handledOrderBoxes = [];
        foreach ($orders as $order) {
            foreach ($this->orderBoxLogs($order)->groupBy('box_id') as $boxId => $rows) {
                if (! $boxId) {
                    continue;
                }
                $handledOrderBoxes[$order->id.':'.$boxId] = true;
                $this->addCashPreview(
                    $cashByCurrency,
                    $boxCurrencies,
                    (int) $boxId,
                    (float) $rows->sum(function (BoxLog $log) {
                        $value = (float) ($log->value ?? 0);
                        if (abs($value) < 0.0001 && isset($log->transfered_balance)) {
                            $value = (float) $log->transfered_balance;
                        }
                        if ($log->type === 'minus' && $value > 0) {
                            $value *= -1;
                        }

                        return $value;
                    })
                );
            }
        }
        foreach ($settlements->groupBy(fn (SalesOrderSettlement $row) => $row->sales_order_id.':'.$row->box_id) as $key => $rows) {
            if (isset($handledOrderBoxes[$key])) {
                continue;
            }
            $boxId = $rows->first()?->box_id;
            if (! $boxId) {
                continue;
            }
            $this->addCashPreview(
                $cashByCurrency,
                $boxCurrencies,
                (int) $boxId,
                (float) $rows->sum(
                    fn (SalesOrderSettlement $settlement) => $settlement->cash_amount ?? $settlement->amount
                )
            );
        }

        [$linkedInstantSales, $financialReturns] = $this->purgeBlockers($orderIds);

        $allOrdersCount = SalesOrder::query()->count();
        $resetsCounters = $mode === self::MODE_ORDERS_ONLY_RESET;

        return [
            'orders_count' => $orderIds->count(),
            'max_order_id' => $maxId,
            'cutoff_at' => $cutoff->toIso8601String(),
            'oldest_order_at' => (clone $query)->min('created_at'),
            'latest_order_at' => (clone $query)->max('created_at'),
            'status_counts' => $statuses,
            'items_count' => Schema::hasTable('sales_order_items')
                ? DB::table('sales_order_items')->whereIn('sales_order_id', $orderIds)->count()
                : 0,
            'media_count' => Schema::hasTable('sales_order_media')
                ? DB::table('sales_order_media')->whereIn('sales_order_id', $orderIds)->count()
                : 0,
            'returns_count' => Schema::hasTable('sales_returns')
                ? DB::table('sales_returns')->whereIn('sales_order_id', $orderIds)->count()
                : 0,
            'settlements_count' => $settlements->count(),
            'net_cash_by_currency' => $cashByCurrency,
            'linked_instant_sales_count' => $linkedInstantSales,
            'financial_returns_count' => $financialReturns,
            'mode' => $mode,
            'all_orders_count' => $allOrdersCount,
            'will_reset_counters' => $resetsCounters,
            'can_reset_counters' => ! $resetsCounters || $orderIds->count() === $allOrdersCount,
            'shiply_parcels_count' => Schema::hasColumn('sales_order_deliveries', 'shiply_parcel_code')
                ? DB::table('sales_order_deliveries')
                    ->whereIn('sales_order_id', $orderIds)
                    ->whereNotNull('shiply_parcel_code')
                    ->count()
                : 0,
            'can_purge' => $financialReturns === 0
                && $linkedInstantSales === 0
                && (! $resetsCounters || $orderIds->count() === $allOrdersCount),
        ];
    }

    /** @return array<string, mixed> */
    public function purge(
        User $user,
        CarbonInterface $cutoff,
        int $maxOrderId,
        string $mode = self::MODE_WITH_EFFECTS
    ): array {
        $preview = $this->preview($cutoff, $maxOrderId, $mode);
        if (! $preview['can_purge']) {
            $message = $mode === self::MODE_ORDERS_ONLY_RESET && ! $preview['can_reset_counters']
                ? 'تصفير العدادات يتطلب حذف جميع الطلبيات الحالية.'
                : 'توجد فواتير بيع أو مرتجعات مالية مرتبطة بهذه الطلبيات. يجب تنظيفها بشكل منفصل أولاً.';
            throw ValidationException::withMessages([
                'orders' => [$message],
            ]);
        }

        if ((int) $preview['orders_count'] === 0) {
            return $preview;
        }

        $backupSummary = null;
        DB::transaction(function () use ($user, $cutoff, $maxOrderId, $mode, &$backupSummary) {
            $orders = $this->purgeQuery($cutoff, $maxOrderId)
                ->with(['media:id,sales_order_id,path'])
                ->orderByDesc('id')
                ->lockForUpdate()
                ->get();

            [$linkedInstantSales, $financialReturns] = $this->purgeBlockers($orders->pluck('id'));
            if ($linkedInstantSales > 0 || $financialReturns > 0) {
                throw ValidationException::withMessages([
                    'orders' => ['توجد فواتير بيع أو مرتجعات مالية مرتبطة بهذه الطلبيات. يجب تنظيفها بشكل منفصل أولاً.'],
                ]);
            }
            if ($mode === self::MODE_ORDERS_ONLY_RESET && $orders->count() !== SalesOrder::query()->count()) {
                throw ValidationException::withMessages([
                    'orders' => ['تصفير العدادات يتطلب حذف جميع الطلبيات الحالية.'],
                ]);
            }

            $backup = $this->createBackup($orders, $user, $cutoff, $maxOrderId, $mode);
            $backupSummary = $this->backupSummary($backup);

            if ($mode === self::MODE_WITH_EFFECTS) {
                $this->hardDeleteDebtEntries($orders);
                $this->deleteRelatedLogs($orders);
            }

            $batchIds = [];
            $expenseIds = [];
            foreach ($orders as $order) {
                $settlements = SalesOrderSettlement::query()
                    ->where('sales_order_id', $order->id)
                    ->lockForUpdate()
                    ->get();
                if ($mode === self::MODE_WITH_EFFECTS) {
                    $this->reverseStockImpact($order, (int) $user->id);
                    $this->reverseCashImpact($order, $settlements);
                }

                $batchIds = array_merge(
                    $batchIds,
                    $settlements->pluck('delivery_company_settlement_batch_id')->filter()->map(fn ($id) => (int) $id)->all()
                );
                $expenseIds = array_merge(
                    $expenseIds,
                    $settlements->pluck('carrier_fee_expense_id')->filter()->map(fn ($id) => (int) $id)->all()
                );
                $order->delete();
            }

            if ($mode === self::MODE_WITH_EFFECTS) {
                if ($expenseIds !== []) {
                    Expense::query()->whereIn('id', array_values(array_unique($expenseIds)))->delete();
                }
                $this->refreshSettlementBatches(array_values(array_unique($batchIds)));
            } else {
                $this->resetSalesOrderSerialCounter();
            }
        }, 3);

        if ($mode === self::MODE_ORDERS_ONLY_RESET) {
            $this->resetSalesOrdersAutoIncrement();
        }

        return array_merge($preview, ['backup' => $backupSummary]);
    }

    /** @return list<array<string, mixed>> */
    public function backups(): array
    {
        return SalesOrderPurgeBackup::query()
            ->with(['creator:id,name', 'restoredBy:id,name'])
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (SalesOrderPurgeBackup $backup) => $this->backupSummary($backup))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    public function restoreBackup(User $user, int $backupId): array
    {
        $restoredMode = self::MODE_WITH_EFFECTS;
        $result = DB::transaction(function () use ($user, $backupId, &$restoredMode) {
            $backup = SalesOrderPurgeBackup::query()->lockForUpdate()->findOrFail($backupId);
            if ($backup->status !== 'available' || $backup->restored_at !== null) {
                throw ValidationException::withMessages([
                    'backup' => ['تم استرجاع هذه النسخة مسبقاً.'],
                ]);
            }

            $payload = is_array($backup->payload) ? $backup->payload : [];
            $restoredMode = (string) ($payload['mode'] ?? $backup->mode ?? self::MODE_WITH_EFFECTS);
            $tables = is_array($payload['tables'] ?? null) ? $payload['tables'] : [];
            $orderRows = $this->tablePayloadRows($tables, 'sales_orders');
            if ($orderRows === []) {
                throw ValidationException::withMessages([
                    'backup' => ['نسخة الاسترجاع لا تحتوي على طلبيات.'],
                ]);
            }
            $this->assertOrdersCanBeRestored($orderRows);

            if ($restoredMode === self::MODE_WITH_EFFECTS) {
                $this->restoreRowsIfMissing('delivery_company_settlement_batches', $this->tablePayloadRows($tables, 'delivery_company_settlement_batches'));
                $this->restoreRowsIfMissing('expenses', $this->tablePayloadRows($tables, 'expenses'));
            }
            $this->restoreSelfReferencingRows('sales_orders', $orderRows, ['parent_order_id', 'root_order_id']);
            $this->restoreRows('sales_order_packages', $this->tablePayloadRows($tables, 'sales_order_packages'));
            $this->restoreRows('sales_order_items', $this->tablePayloadRows($tables, 'sales_order_items'));
            $this->restoreRows('sales_order_status_logs', $this->tablePayloadRows($tables, 'sales_order_status_logs'));
            $this->restoreRows('sales_order_media', $this->tablePayloadRows($tables, 'sales_order_media'));
            $this->restoreRows('sales_order_deliveries', $this->tablePayloadRows($tables, 'sales_order_deliveries'));
            $this->restoreRows('sales_order_shiply_events', $this->tablePayloadRows($tables, 'sales_order_shiply_events'));
            if ($restoredMode === self::MODE_WITH_EFFECTS) {
                $this->restoreRows('debt_transactions', $this->tablePayloadRows($tables, 'debt_transactions'));
            }
            $this->restoreSelfReferencingRows(
                'sales_returns',
                $this->tablePayloadRows($tables, 'sales_returns'),
                ['replaces_sales_return_id', 'replacement_sales_return_id']
            );
            $this->restoreRows('sales_return_items', $this->tablePayloadRows($tables, 'sales_return_items'));
            $this->restoreRows('sales_order_settlements', $this->tablePayloadRows($tables, 'sales_order_settlements'));
            $this->restoreRows('sales_order_stock_shortages', $this->tablePayloadRows($tables, 'sales_order_stock_shortages'));
            if ($restoredMode === self::MODE_WITH_EFFECTS) {
                $this->restoreRows('debt_ledger_activity_logs', $this->tablePayloadRows($tables, 'debt_ledger_activity_logs'));
                $this->restoreRows('admin_notifications', $this->tablePayloadRows($tables, 'admin_notifications'));
                $this->restoreRows('employee_activity_logs', $this->tablePayloadRows($tables, 'employee_activity_logs'));
                $this->restoreRows('box_logs', $this->tablePayloadRows($tables, 'box_logs'));

                $this->restoreCashEffects($payload['cash_effects'] ?? []);
                $this->restoreStockEffects($payload['stock_effects'] ?? [], (int) $user->id, (int) $backup->id);
                $this->recalculateBackupDebtPeople($this->tablePayloadRows($tables, 'debt_transactions'));

                $batchIds = collect($this->tablePayloadRows($tables, 'sales_order_settlements'))
                    ->pluck('delivery_company_settlement_batch_id')
                    ->filter()
                    ->map(fn ($id) => (int) $id)
                    ->unique()
                    ->values()
                    ->all();
                $this->refreshSettlementBatches($batchIds);
            } else {
                $this->restoreSalesOrderSerialCounter($payload['sales_order_serial_counter'] ?? null);
            }

            $backup->update([
                'status' => 'restored',
                'restored_by' => $user->id,
                'restored_at' => now(),
            ]);

            return $this->backupSummary($backup->fresh(['creator:id,name', 'restoredBy:id,name']));
        }, 3);

        if ($restoredMode === self::MODE_ORDERS_ONLY_RESET) {
            $this->setSalesOrdersAutoIncrementAfterCurrentMax();
        }

        return $result;
    }

    /**
     * @param  Collection<int, SalesOrder>  $orders
     */
    private function createBackup(
        Collection $orders,
        User $user,
        CarbonInterface $cutoff,
        int $maxOrderId,
        string $mode
    ): SalesOrderPurgeBackup {
        $orderIds = $orders->pluck('id')->map(fn ($id) => (int) $id)->values();
        $settlements = $this->rowsWhereIn('sales_order_settlements', 'sales_order_id', $orderIds);
        $returnRows = $this->rowsWhereIn('sales_returns', 'sales_order_id', $orderIds);
        $returnIds = collect($returnRows)->pluck('id')->filter()->map(fn ($id) => (int) $id)->values();
        $debtRows = $this->currentDebtTransactions($orders)
            ->map(fn (DebtTransaction $row) => (array) $row->getRawOriginal())
            ->values()
            ->all();
        $debtIds = collect($debtRows)->pluck('id')->filter()->map(fn ($id) => (int) $id)->values();
        $boxLogRows = $orders
            ->flatMap(fn (SalesOrder $order) => $this->orderBoxLogs($order))
            ->unique('id')
            ->sortBy('id')
            ->map(fn (BoxLog $row) => (array) $row->getRawOriginal())
            ->values()
            ->all();

        $expenseIds = collect($settlements)->pluck('carrier_fee_expense_id')->filter()->map(fn ($id) => (int) $id)->values();
        $batchIds = collect($settlements)->pluck('delivery_company_settlement_batch_id')->filter()->map(fn ($id) => (int) $id)->values();

        $tables = [
            'sales_orders' => $this->rowsWhereIn('sales_orders', 'id', $orderIds),
            'sales_order_packages' => $this->rowsWhereIn('sales_order_packages', 'sales_order_id', $orderIds),
            'sales_order_items' => $this->rowsWhereIn('sales_order_items', 'sales_order_id', $orderIds),
            'sales_order_status_logs' => $this->rowsWhereIn('sales_order_status_logs', 'sales_order_id', $orderIds),
            'sales_order_media' => $this->rowsWhereIn('sales_order_media', 'sales_order_id', $orderIds),
            'sales_order_deliveries' => $this->rowsWhereIn('sales_order_deliveries', 'sales_order_id', $orderIds),
            'sales_order_shiply_events' => $this->rowsWhereIn('sales_order_shiply_events', 'sales_order_id', $orderIds),
            'sales_order_stock_shortages' => $this->rowsWhereIn('sales_order_stock_shortages', 'sales_order_id', $orderIds),
            'sales_order_settlements' => $settlements,
            'sales_returns' => $returnRows,
            'sales_return_items' => $this->rowsWhereIn('sales_return_items', 'sales_return_id', $returnIds),
            'debt_transactions' => $debtRows,
            'debt_ledger_activity_logs' => $this->rowsWhereIn('debt_ledger_activity_logs', 'debt_transaction_id', $debtIds),
            'admin_notifications' => $this->currentRelatedLogRows(
                $orders,
                'admin_notifications',
                'related_type',
                'related_id'
            ),
            'employee_activity_logs' => $this->currentRelatedLogRows(
                $orders,
                'employee_activity_logs',
                'subject_type',
                'subject_id'
            ),
            'box_logs' => $boxLogRows,
            'expenses' => $this->rowsWhereIn('expenses', 'id', $expenseIds),
            'delivery_company_settlement_batches' => $this->rowsWhereIn(
                'delivery_company_settlement_batches',
                'id',
                $batchIds
            ),
        ];

        $stockEffects = $orders
            ->flatMap(fn (SalesOrder $order) => $this->stockEffects($order))
            ->values()
            ->all();
        $cashEffects = $this->backupCashEffects($orders);

        return SalesOrderPurgeBackup::query()->create([
            'reference' => (string) Str::uuid(),
            'cutoff_at' => $cutoff,
            'max_order_id' => $maxOrderId,
            'orders_count' => $orders->count(),
            'mode' => $mode,
            'status' => 'available',
            'payload' => [
                'version' => 1,
                'mode' => $mode,
                'tables' => $tables,
                'stock_effects' => $stockEffects,
                'cash_effects' => $cashEffects,
                'sales_order_serial_counter' => $this->salesOrderSerialCounter(),
                'media_files_preserved' => true,
            ],
            'created_by' => $user->id,
        ]);
    }

    /** @return array<string, mixed> */
    private function backupSummary(SalesOrderPurgeBackup $backup): array
    {
        return [
            'id' => (int) $backup->id,
            'reference' => (string) $backup->reference,
            'orders_count' => (int) $backup->orders_count,
            'mode' => (string) $backup->mode,
            'cutoff_at' => $backup->cutoff_at?->toIso8601String(),
            'max_order_id' => (int) $backup->max_order_id,
            'status' => (string) $backup->status,
            'created_at' => $backup->created_at?->toIso8601String(),
            'created_by' => $backup->creator ? [
                'id' => (int) $backup->creator->id,
                'name' => (string) $backup->creator->name,
            ] : null,
            'restored_at' => $backup->restored_at?->toIso8601String(),
            'restored_by' => $backup->restoredBy ? [
                'id' => (int) $backup->restoredBy->id,
                'name' => (string) $backup->restoredBy->name,
            ] : null,
        ];
    }

    /**
     * @param  Collection<int, int>  $ids
     * @return list<array<string, mixed>>
     */
    private function rowsWhereIn(string $table, string $column, Collection $ids): array
    {
        if ($ids->isEmpty() || ! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return [];
        }

        return DB::table($table)
            ->whereIn($column, $ids)
            ->orderBy('id')
            ->get()
            ->map(fn (object $row) => (array) $row)
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, SalesOrder>  $orders
     * @return list<array<string, mixed>>
     */
    private function currentRelatedLogRows(
        Collection $orders,
        string $table,
        string $typeColumn,
        string $idColumn
    ): array {
        if (! Schema::hasTable($table)) {
            return [];
        }

        return $orders->flatMap(function (SalesOrder $order) use ($table, $typeColumn, $idColumn) {
            return DB::table($table)
                ->where($typeColumn, 'sales_order')
                ->where($idColumn, $order->id)
                ->where('created_at', '>=', $order->created_at)
                ->get()
                ->map(fn (object $row) => (array) $row);
        })->unique('id')->sortBy('id')->values()->all();
    }

    /** @return Builder<SalesOrder> */
    private function purgeQuery(CarbonInterface $cutoff, ?int $maxOrderId): Builder
    {
        return SalesOrder::query()
            ->where('created_at', '<=', $cutoff)
            ->when($maxOrderId !== null, fn (Builder $query) => $query->where('id', '<=', $maxOrderId));
    }

    /**
     * @param  Collection<int, int>  $orderIds
     * @return array{0: int, 1: int}
     */
    private function purgeBlockers(Collection $orderIds): array
    {
        $linkedInstantSales = Schema::hasColumn('instant_sales', 'sales_order_id')
            ? DB::table('instant_sales')->whereIn('sales_order_id', $orderIds)->count()
            : 0;
        $financialReturns = Schema::hasTable('sales_returns')
            ? DB::table('sales_returns')
                ->whereIn('sales_order_id', $orderIds)
                ->where(function ($returns) {
                    $returns->where('cash_refund_amount', '>', 0)
                        ->orWhere('credit_amount', '>', 0)
                        ->orWhereNotNull('debt_transaction_id');
                })
                ->count()
            : 0;

        return [(int) $linkedInstantSales, (int) $financialReturns];
    }

    private function reverseStockImpact(SalesOrder $order, int $userId): void
    {
        $this->stockEffects($order)->each(function (array $effect) use ($order, $userId) {
            $netQuantity = (int) $effect['net_quantity'];
            $product = Product::withTrashed()->find($effect['product_id']);
            if (! $product || $netQuantity === 0) {
                return;
            }

            $note = 'تنظيف طلبية تجريبية '.($order->serial_number ?? '#'.$order->id);
            if ($netQuantity < 0) {
                $quantity = abs($netQuantity);
                $unitCost = $effect['unit_cost'];
                if ($unitCost !== null && (float) $unitCost >= 0) {
                    $this->costingService->addOwnedStock(
                        product: $product,
                        quantity: $quantity,
                        unitCost: (float) $unitCost,
                        currency: 'شيكل',
                        sourceType: 'sales_order_purge',
                        sourceId: (int) $order->id,
                        sizeColorId: $effect['size_color_id'],
                        sizeId: $effect['size_id'],
                        userId: $userId,
                        note: $note,
                        movementType: ProductStockMovement::TYPE_SALE_CANCEL,
                        reason: $note,
                        idempotencyKey: 'sales-order-purge-'.$order->id.'-'.$effect['product_id'].'-'.($effect['size_id'] ?: 0).'-'.($effect['size_color_id'] ?: 0),
                        movementReferenceType: 'sales_order_purge',
                        movementReferenceId: (int) $order->id,
                    );

                    return;
                }

                $this->stockService->adjustStock(
                    product: $product,
                    quantityDelta: $quantity,
                    type: ProductStockMovement::TYPE_SALE_CANCEL,
                    sizeColorId: $effect['size_color_id'],
                    referenceType: 'sales_order_purge',
                    referenceId: (int) $order->id,
                    note: $note,
                    userId: $userId,
                    reason: $note,
                );

                return;
            }

            $this->stockService->adjustStock(
                product: $product,
                quantityDelta: -$netQuantity,
                type: ProductStockMovement::TYPE_STOCK_ADJUSTMENT_OUT,
                sizeColorId: $effect['size_color_id'],
                referenceType: 'sales_order_purge',
                referenceId: (int) $order->id,
                note: $note,
                userId: $userId,
                reason: $note,
            );
        });
    }

    /** @return Collection<int, array<string, mixed>> */
    private function stockEffects(SalesOrder $order): Collection
    {
        if (! Schema::hasTable('product_stock_movements')) {
            return collect();
        }

        $returnIds = Schema::hasTable('sales_returns')
            ? DB::table('sales_returns')->where('sales_order_id', $order->id)->pluck('id')
            : collect();
        $movements = ProductStockMovement::query()
            ->where('created_at', '>=', $order->created_at)
            ->where(function ($query) use ($order, $returnIds) {
                $query->where(function ($salesOrder) use ($order) {
                    $salesOrder->where('reference_type', 'sales_order')
                        ->where('reference_id', $order->id);
                });
                if ($returnIds->isNotEmpty()) {
                    $query->orWhere(function ($returns) use ($returnIds) {
                        $returns->whereIn('reference_type', ['sales_return', 'sales_return_cancel'])
                            ->whereIn('reference_id', $returnIds);
                    });
                }
            })
            ->orderBy('id')
            ->get();

        return $movements
            ->groupBy(fn (ProductStockMovement $movement) => implode(':', [
                $movement->product_id,
                $movement->size_id ?: 0,
                $movement->size_color_id ?: 0,
            ]))
            ->map(function (Collection $rows) use ($order) {
                /** @var ProductStockMovement $latest */
                $latest = $rows->last();
                $netQuantity = (int) $rows->sum('quantity');
                $costMovement = $rows
                    ->where('quantity', $netQuantity < 0 ? '<' : '>', 0)
                    ->whereNotNull('unit_cost')
                    ->last();

                return [
                    'order_id' => (int) $order->id,
                    'product_id' => (int) $latest->product_id,
                    'size_id' => $latest->size_id ? (int) $latest->size_id : null,
                    'size_color_id' => $latest->size_color_id ? (int) $latest->size_color_id : null,
                    'net_quantity' => $netQuantity,
                    'unit_cost' => $costMovement?->unit_cost !== null ? (float) $costMovement->unit_cost : null,
                ];
            })
            ->filter(fn (array $effect) => $effect['net_quantity'] !== 0)
            ->values();
    }

    /** @param Collection<int, SalesOrderSettlement> $settlements */
    private function reverseCashImpact(SalesOrder $order, Collection $settlements): void
    {
        $boxLogs = $this->orderBoxLogs($order);
        foreach ($this->cashEffectsForOrder($order, $settlements) as $effect) {
            $boxId = (int) $effect['box_id'];
            $netCash = (float) $effect['amount'];
            if (abs($netCash) >= 0.0001) {
                $box = Box::query()->lockForUpdate()->find($boxId);
                if ($box) {
                    $box->update(['total' => round((float) $box->total - $netCash, 2)]);
                }
            }
        }

        if ($boxLogs->isNotEmpty()) {
            BoxLog::query()->whereIn('id', $boxLogs->pluck('id'))->delete();
        }
    }

    /**
     * @param  Collection<int, SalesOrderSettlement>  $settlements
     * @return Collection<int, array{box_id:int,amount:float}>
     */
    private function cashEffectsForOrder(SalesOrder $order, Collection $settlements): Collection
    {
        $effects = collect();
        $boxesHandledByLogs = [];
        foreach ($this->orderBoxLogs($order)->groupBy('box_id') as $boxId => $rows) {
            if (! $boxId) {
                continue;
            }
            $effects->push([
                'box_id' => (int) $boxId,
                'amount' => round((float) $rows->sum(fn (BoxLog $log) => $this->signedBoxLogValue($log)), 2),
            ]);
            $boxesHandledByLogs[] = (int) $boxId;
        }

        foreach ($settlements->groupBy('box_id') as $boxId => $rows) {
            if (! $boxId || in_array((int) $boxId, $boxesHandledByLogs, true)) {
                continue;
            }
            $effects->push([
                'box_id' => (int) $boxId,
                'amount' => round((float) $rows->sum(
                    fn (SalesOrderSettlement $settlement) => $settlement->cash_amount ?? $settlement->amount
                ), 2),
            ]);
        }

        return $effects;
    }

    private function signedBoxLogValue(BoxLog $log): float
    {
        $value = (float) ($log->value ?? 0);
        if (abs($value) < 0.0001 && isset($log->transfered_balance)) {
            $value = (float) $log->transfered_balance;
        }
        if ($log->type === 'minus' && $value > 0) {
            $value *= -1;
        }

        return $value;
    }

    /**
     * @param  Collection<int, SalesOrder>  $orders
     * @return list<array{box_id:int,amount:float}>
     */
    private function backupCashEffects(Collection $orders): array
    {
        return $orders
            ->flatMap(function (SalesOrder $order) {
                $settlements = SalesOrderSettlement::query()
                    ->where('sales_order_id', $order->id)
                    ->get();

                return $this->cashEffectsForOrder($order, $settlements);
            })
            ->groupBy('box_id')
            ->map(fn (Collection $rows, $boxId) => [
                'box_id' => (int) $boxId,
                'amount' => round((float) $rows->sum('amount'), 2),
            ])
            ->values()
            ->all();
    }

    /** @return Collection<int, BoxLog> */
    private function orderBoxLogs(SalesOrder $order): Collection
    {
        $serial = trim((string) $order->serial_number);

        return BoxLog::query()
            ->where('created_at', '>=', $order->created_at)
            ->where(function ($logs) use ($order, $serial) {
                if ($serial !== '') {
                    $logs->where('note', 'like', '%طلبية #'.$order->id.' — '.$serial)
                        ->orWhere('description', 'like', '%طلبية '.$serial);

                    return;
                }

                $logs->where('note', 'like', '%طلبية #'.$order->id.' — %')
                    ->orWhere('note', 'طلبية #'.$order->id);
            })
            ->get();
    }

    /**
     * @param  array<string, float>  $cashByCurrency
     * @param  array<int, string|null>  $boxCurrencies
     */
    private function addCashPreview(
        array &$cashByCurrency,
        array &$boxCurrencies,
        int $boxId,
        float $amount
    ): void {
        if (! array_key_exists($boxId, $boxCurrencies)) {
            $boxCurrencies[$boxId] = Box::query()->whereKey($boxId)->value('currency');
        }
        $currency = (string) ($boxCurrencies[$boxId] ?: 'شيكل');
        $cashByCurrency[$currency] = round(($cashByCurrency[$currency] ?? 0) + $amount, 2);
    }

    /** @param list<array<string, mixed>> $orderRows */
    private function assertOrdersCanBeRestored(array $orderRows): void
    {
        $ids = collect($orderRows)->pluck('id')->filter()->map(fn ($id) => (int) $id)->values();
        $serials = collect($orderRows)->pluck('serial_number')->filter()->map(fn ($serial) => (string) $serial)->values();
        $conflict = SalesOrder::query()
            ->whereIn('id', $ids)
            ->when($serials->isNotEmpty(), fn (Builder $query) => $query->orWhereIn('serial_number', $serials))
            ->exists();

        if ($conflict) {
            throw ValidationException::withMessages([
                'backup' => ['لا يمكن الاسترجاع لأن رقم طلبية أو معرّفاً قديماً مستخدم حالياً.'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $tables
     * @return list<array<string, mixed>>
     */
    private function tablePayloadRows(array $tables, string $table): array
    {
        $rows = $tables[$table] ?? [];

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /** @param list<array<string, mixed>> $rows */
    private function restoreRows(string $table, array $rows): void
    {
        if ($rows === [] || ! Schema::hasTable($table)) {
            return;
        }

        $columns = array_flip(Schema::getColumnListing($table));
        $filtered = array_map(
            fn (array $row) => array_intersect_key($row, $columns),
            $rows
        );
        foreach (array_chunk($filtered, 250) as $chunk) {
            DB::table($table)->insert($chunk);
        }
    }

    /** @param list<array<string, mixed>> $rows */
    private function restoreRowsIfMissing(string $table, array $rows): void
    {
        if ($rows === [] || ! Schema::hasTable($table) || ! Schema::hasColumn($table, 'id')) {
            return;
        }
        $existing = DB::table($table)
            ->whereIn('id', collect($rows)->pluck('id')->filter())
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $this->restoreRows($table, array_values(array_filter(
            $rows,
            fn (array $row) => ! in_array((int) ($row['id'] ?? 0), $existing, true)
        )));
    }

    /** @return array<string, mixed>|null */
    private function salesOrderSerialCounter(): ?array
    {
        if (! Schema::hasTable('document_serials')) {
            return null;
        }
        $row = DB::table('document_serials')
            ->where('year', 0)
            ->where('document_type', 'SO')
            ->first();

        return $row ? (array) $row : null;
    }

    private function resetSalesOrderSerialCounter(): void
    {
        if (! Schema::hasTable('document_serials')) {
            return;
        }
        DB::table('document_serials')->updateOrInsert(
            ['year' => 0, 'document_type' => 'SO'],
            ['last_number' => 0, 'updated_at' => now(), 'created_at' => now()]
        );
    }

    private function restoreSalesOrderSerialCounter(mixed $counter): void
    {
        if (! is_array($counter) || ! Schema::hasTable('document_serials')) {
            return;
        }
        DB::table('document_serials')->updateOrInsert(
            ['year' => 0, 'document_type' => 'SO'],
            [
                'last_number' => (int) ($counter['last_number'] ?? 0),
                'updated_at' => $counter['updated_at'] ?? now(),
                'created_at' => $counter['created_at'] ?? now(),
            ]
        );
    }

    private function resetSalesOrdersAutoIncrement(): void
    {
        if (SalesOrder::query()->exists()) {
            throw new \RuntimeException('تعذر تصفير عداد الطلبيات لأن الجدول ليس فارغاً.');
        }
        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE sales_orders AUTO_INCREMENT = 1');
        } elseif ($driver === 'sqlite') {
            DB::table('sqlite_sequence')->where('name', 'sales_orders')->delete();
        } elseif ($driver === 'pgsql') {
            DB::statement("SELECT setval(pg_get_serial_sequence('sales_orders', 'id'), 1, false)");
        }
    }

    private function setSalesOrdersAutoIncrementAfterCurrentMax(): void
    {
        $next = ((int) SalesOrder::query()->max('id')) + 1;
        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE sales_orders AUTO_INCREMENT = '.max(1, $next));
        } elseif ($driver === 'sqlite') {
            DB::table('sqlite_sequence')->updateOrInsert(
                ['name' => 'sales_orders'],
                ['seq' => max(0, $next - 1)]
            );
        } elseif ($driver === 'pgsql') {
            DB::statement("SELECT setval(pg_get_serial_sequence('sales_orders', 'id'), ".max(1, $next).', false)');
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $selfReferenceColumns
     */
    private function restoreSelfReferencingRows(
        string $table,
        array $rows,
        array $selfReferenceColumns
    ): void {
        if ($rows === []) {
            return;
        }

        $references = [];
        $insertRows = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $references[$id] = [];
            foreach ($selfReferenceColumns as $column) {
                if (array_key_exists($column, $row)) {
                    $references[$id][$column] = $row[$column];
                    $row[$column] = null;
                }
            }
            $insertRows[] = $row;
        }

        $this->restoreRows($table, $insertRows);
        foreach ($references as $id => $values) {
            $values = array_filter($values, fn ($value) => $value !== null);
            if ($id > 0 && $values !== []) {
                DB::table($table)->where('id', $id)->update($values);
            }
        }
    }

    private function restoreCashEffects(mixed $effects): void
    {
        if (! is_array($effects)) {
            return;
        }

        foreach ($effects as $effect) {
            if (! is_array($effect) || empty($effect['box_id'])) {
                continue;
            }
            $box = Box::query()->lockForUpdate()->find((int) $effect['box_id']);
            if (! $box) {
                throw ValidationException::withMessages([
                    'backup' => ['تعذر الاسترجاع لأن أحد الصناديق المرتبطة لم يعد موجوداً.'],
                ]);
            }
            $box->update([
                'total' => round((float) $box->total + (float) ($effect['amount'] ?? 0), 2),
            ]);
        }
    }

    private function restoreStockEffects(mixed $effects, int $userId, int $backupId): void
    {
        if (! is_array($effects)) {
            return;
        }

        foreach ($effects as $effect) {
            if (! is_array($effect)) {
                continue;
            }
            $netQuantity = (int) ($effect['net_quantity'] ?? 0);
            if ($netQuantity === 0) {
                continue;
            }
            $product = Product::withTrashed()->find((int) ($effect['product_id'] ?? 0));
            if (! $product) {
                throw ValidationException::withMessages([
                    'backup' => ['تعذر الاسترجاع لأن أحد المنتجات المرتبطة لم يعد موجوداً.'],
                ]);
            }
            $orderId = (int) ($effect['order_id'] ?? 0);
            $note = 'استرجاع طلبية من نسخة التنظيف #'.$backupId;
            if ($netQuantity < 0) {
                $this->costingService->consumeOwnedStock(
                    product: $product,
                    quantity: abs($netQuantity),
                    movementType: ProductStockMovement::TYPE_SALE,
                    referenceType: 'sales_order_restore',
                    referenceId: $orderId,
                    sizeColorId: ! empty($effect['size_color_id']) ? (int) $effect['size_color_id'] : null,
                    sizeId: ! empty($effect['size_id']) ? (int) $effect['size_id'] : null,
                    userId: $userId,
                    note: $note,
                    reason: $note,
                );

                continue;
            }

            $unitCost = $effect['unit_cost'] ?? null;
            if ($unitCost !== null && (float) $unitCost >= 0) {
                $this->costingService->addOwnedStock(
                    product: $product,
                    quantity: $netQuantity,
                    unitCost: (float) $unitCost,
                    currency: 'شيكل',
                    sourceType: 'sales_order_restore',
                    sourceId: $orderId,
                    sizeColorId: ! empty($effect['size_color_id']) ? (int) $effect['size_color_id'] : null,
                    sizeId: ! empty($effect['size_id']) ? (int) $effect['size_id'] : null,
                    userId: $userId,
                    note: $note,
                    movementType: ProductStockMovement::TYPE_RETURN,
                    reason: $note,
                    idempotencyKey: 'sales-order-restore-'.$backupId.'-'.$orderId.'-'.$effect['product_id'].'-'.($effect['size_id'] ?? 0).'-'.($effect['size_color_id'] ?? 0),
                    movementReferenceType: 'sales_order_restore',
                    movementReferenceId: $orderId,
                );

                continue;
            }

            $this->stockService->adjustStock(
                product: $product,
                quantityDelta: $netQuantity,
                type: ProductStockMovement::TYPE_RETURN,
                sizeColorId: ! empty($effect['size_color_id']) ? (int) $effect['size_color_id'] : null,
                referenceType: 'sales_order_restore',
                referenceId: $orderId,
                note: $note,
                userId: $userId,
                reason: $note,
            );
        }
    }

    /** @param list<array<string, mixed>> $transactions */
    private function recalculateBackupDebtPeople(array $transactions): void
    {
        collect($transactions)
            ->map(fn (array $transaction) => [
                'customer_id' => ! empty($transaction['customer_id']) ? (int) $transaction['customer_id'] : null,
                'seller_id' => ! empty($transaction['seller_id']) ? (int) $transaction['seller_id'] : null,
            ])
            ->unique(fn (array $person) => ($person['customer_id'] ?? 0).':'.($person['seller_id'] ?? 0))
            ->each(fn (array $person) => $this->debtLedgerService->recalculateBalances(
                $person['customer_id'],
                $person['seller_id']
            ));
    }

    /** @param Collection<int, SalesOrder> $orders */
    private function deleteRelatedLogs(Collection $orders): void
    {
        if ($orders->isEmpty()) {
            return;
        }
        foreach ($orders as $order) {
            if (Schema::hasTable('admin_notifications')) {
                DB::table('admin_notifications')
                    ->where('related_type', 'sales_order')
                    ->where('related_id', $order->id)
                    ->where('created_at', '>=', $order->created_at)
                    ->delete();
            }
            if (Schema::hasTable('employee_activity_logs')) {
                DB::table('employee_activity_logs')
                    ->where('subject_type', 'sales_order')
                    ->where('subject_id', $order->id)
                    ->where('created_at', '>=', $order->created_at)
                    ->delete();
            }
        }
    }

    /** @param Collection<int, SalesOrder> $orders */
    private function hardDeleteDebtEntries(Collection $orders): void
    {
        if ($orders->isEmpty()) {
            return;
        }

        $transactions = $this->currentDebtTransactions($orders);
        $people = $transactions
            ->map(fn (DebtTransaction $transaction) => [
                'customer_id' => $transaction->customer_id ? (int) $transaction->customer_id : null,
                'seller_id' => $transaction->seller_id ? (int) $transaction->seller_id : null,
            ])
            ->unique(fn (array $person) => ($person['customer_id'] ?? 0).':'.($person['seller_id'] ?? 0));

        if ($transactions->isNotEmpty()) {
            if (Schema::hasTable('debt_ledger_activity_logs')) {
                DB::table('debt_ledger_activity_logs')
                    ->whereIn('debt_transaction_id', $transactions->pluck('id'))
                    ->delete();
            }
            DebtTransaction::query()->whereIn('id', $transactions->pluck('id'))->delete();
        }

        foreach ($people as $person) {
            $this->debtLedgerService->recalculateBalances(
                $person['customer_id'],
                $person['seller_id']
            );
        }
    }

    /**
     * @param  Collection<int, SalesOrder>  $orders
     * @return Collection<int, DebtTransaction>
     */
    private function currentDebtTransactions(Collection $orders): Collection
    {
        if ($orders->isEmpty() || ! Schema::hasTable('debt_transactions')) {
            return collect();
        }

        return $orders->flatMap(fn (SalesOrder $order) => DebtTransaction::query()
            ->where('source', 'sales_order')
            ->where('source_id', $order->id)
            ->where('created_at', '>=', $order->created_at)
            ->get())->unique('id')->values();
    }

    /** @param list<int> $batchIds */
    private function refreshSettlementBatches(array $batchIds): void
    {
        foreach ($batchIds as $batchId) {
            $batch = DeliveryCompanySettlementBatch::query()->find($batchId);
            if (! $batch) {
                continue;
            }
            $remaining = SalesOrderSettlement::query()
                ->where('delivery_company_settlement_batch_id', $batchId)
                ->get();
            if ($remaining->isEmpty()) {
                $batch->delete();

                continue;
            }
            $batch->update([
                'amount' => round((float) $remaining->sum('amount'), 2),
                'cash_amount' => round((float) $remaining->sum(
                    fn (SalesOrderSettlement $settlement) => $settlement->cash_amount ?? $settlement->amount
                ), 2),
                'carrier_fee' => round((float) $remaining->sum('carrier_fee'), 2),
                'orders_count' => $remaining->pluck('sales_order_id')->unique()->count(),
            ]);
        }
    }
}
