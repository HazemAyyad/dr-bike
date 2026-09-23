<?php

namespace App\Services;

use App\Models\InstantSale;
use App\Models\InventoryCostAllocation;
use App\Models\InventoryCostLayer;
use App\Models\MaintenanceProduct;
use App\Models\ProductStockMovement;
use App\Models\ReturnModel;
use App\Models\SalesOrder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class InventoryCostIntegrityService
{
    private const EPSILON = 0.0001;

    /** @return array<string, mixed> */
    public function inspectInstantSale(InstantSale $sale): array
    {
        if ($sale->parent_id) {
            $sale = InstantSale::query()->findOrFail($sale->parent_id);
        }

        $sale->loadMissing('subProducts');
        if (($sale->sale_kind ?? 'regular') === 'adjustment') {
            return $this->result([], [], 0.0, false);
        }

        $lines = collect([$sale])->concat($sale->subProducts)
            ->filter(fn (InstantSale $line) => (int) $line->product_id > 0 && (float) $line->quantity > 0)
            ->values();

        if ($lines->isEmpty()) {
            return $this->result([], [], 0.0, false);
        }

        if ($sale->maintenance_id) {
            return $this->inspectMaintenanceSale($sale, $lines);
        }

        $issues = [];
        $repairs = [];
        $totalCost = 0.0;
        foreach ($lines as $line) {
            $inspection = $this->inspectAllocationSet(
                'instant_sale',
                (int) $line->id,
                (int) $line->product_id,
                $line->size_color_id ? (int) $line->size_color_id : null,
                (float) $line->quantity,
                $line->inventory_total_cost !== null ? (float) $line->inventory_total_cost : null,
                'instant_sale',
                (int) $line->id,
            );
            $issues = array_merge($issues, $inspection['issues']);
            $totalCost += (float) $inspection['total_cost'];
            if ($inspection['repair']) {
                $repairs[] = array_merge($inspection['repair'], [
                    'table' => 'instant_sales',
                    'id' => (int) $line->id,
                ]);
            }
        }

        return $this->result($issues, $repairs, $totalCost, true);
    }

    /** @return array<string, mixed> */
    public function inspectSalesOrder(SalesOrder $order): array
    {
        $order->loadMissing('items');
        $items = $order->items
            ->filter(fn ($item) => ! $item->is_hidden && (float) ($item->dispatched_qty ?: $item->delivered_qty ?: $item->quantity) > 0)
            ->groupBy(fn ($item) => $this->identity((int) $item->product_id, $item->size_color_id));

        $issues = [];
        $totalCost = 0.0;
        foreach ($items as $rows) {
            $first = $rows->first();
            $quantity = (float) $rows->sum(fn ($item) => (float) ($item->dispatched_qty ?: $item->delivered_qty ?: $item->quantity));
            $inspection = $this->inspectAllocationSet(
                'sales_order',
                (int) $order->id,
                (int) $first->product_id,
                $first->size_color_id ? (int) $first->size_color_id : null,
                $quantity,
                null,
                'sales_order',
                (int) $order->id,
                requireSnapshot: false,
            );
            $issues = array_merge($issues, $inspection['issues']);
            $totalCost += (float) $inspection['total_cost'];
        }

        return $this->result($issues, [], $totalCost, $items->isNotEmpty());
    }

    /** @return array<string, mixed> */
    public function inspectPurchaseReturn(ReturnModel $return): array
    {
        $return->loadMissing('items');
        $groups = $return->items
            ->filter(fn ($item) => (float) $item->quantity > 0)
            ->groupBy(fn ($item) => $this->identity((int) $item->product_id, $item->size_color_id));
        $issues = [];
        $totalCost = 0.0;
        foreach ($groups as $rows) {
            $first = $rows->first();
            $expectedQuantity = (float) $rows->sum('quantity');
            $snapshot = (float) $rows->sum('cost_total');
            $inspection = $this->inspectAllocationSet(
                'purchase_return',
                (int) $return->id,
                (int) $first->product_id,
                $first->size_color_id ? (int) $first->size_color_id : null,
                $expectedQuantity,
                $snapshot,
                'purchase_return',
                (int) $return->id,
            );
            $issues = array_merge($issues, $inspection['issues']);
            $totalCost += (float) $inspection['total_cost'];
        }

        return $this->result($issues, [], $totalCost, $groups->isNotEmpty());
    }

    /** @return array<string, mixed> */
    public function assertPurchaseReturnReady(ReturnModel $return): array
    {
        $inspection = $this->inspectPurchaseReturn($return);
        if (! $inspection['ready']) {
            throw new RuntimeException($this->failureMessage($inspection));
        }

        return $inspection;
    }

    /** @return array<string, mixed> */
    public function repairInstantSaleSnapshots(InstantSale $sale): array
    {
        $before = $this->inspectInstantSale($sale);
        if (! $before['repairable']) {
            return $before;
        }

        DB::transaction(function () use ($before) {
            foreach ($before['repairs'] as $repair) {
                DB::table($repair['table'])
                    ->where('id', $repair['id'])
                    ->whereNull('inventory_total_cost')
                    ->update([
                        'inventory_cost_method' => $repair['method'],
                        'inventory_unit_cost' => $repair['unit_cost'],
                        'inventory_total_cost' => $repair['total_cost'],
                        'updated_at' => now(),
                    ]);
            }
        });

        return $this->inspectInstantSale($sale->fresh());
    }

    /** @return array<string, mixed> */
    public function assertInstantSaleReady(InstantSale $sale): array
    {
        $inspection = $this->inspectInstantSale($sale);
        if (! $inspection['ready']) {
            throw new RuntimeException($this->failureMessage($inspection));
        }

        return $inspection;
    }

    /** @return array<string, mixed> */
    public function assertSalesOrderReady(SalesOrder $order): array
    {
        $inspection = $this->inspectSalesOrder($order);
        if (! $inspection['ready']) {
            throw new RuntimeException($this->failureMessage($inspection));
        }

        return $inspection;
    }

    /** @param Collection<int, InstantSale> $saleLines @return array<string, mixed> */
    private function inspectMaintenanceSale(InstantSale $sale, Collection $saleLines): array
    {
        if (! Schema::hasTable('maintenance_products')) {
            return $this->result([$this->issue('missing_cost_table', 'Maintenance products table is unavailable.', [
                'maintenance_id' => (int) $sale->maintenance_id,
            ])], [], 0.0, true);
        }

        $parts = MaintenanceProduct::query()
            ->where('maintenance_id', $sale->maintenance_id)
            ->where('quantity', '>', 0)
            ->orderBy('id')
            ->get();
        $saleGroups = $saleLines->groupBy(fn (InstantSale $line) => $this->identity((int) $line->product_id, $line->size_color_id));
        $partGroups = $parts->groupBy(fn (MaintenanceProduct $line) => $this->identity((int) $line->product_id, $line->size_color_id));
        $issues = [];
        $repairs = [];
        $totalCost = 0.0;

        foreach ($saleGroups as $identity => $lines) {
            $matchingParts = $partGroups->get($identity, collect())->values();
            $first = $lines->first();
            $expectedQuantity = (float) $lines->sum('quantity');
            $partQuantity = (float) $matchingParts->sum('quantity');
            if (abs($partQuantity - $expectedQuantity) > self::EPSILON) {
                $issues[] = $this->issue('maintenance_quantity_mismatch', 'Maintenance sale lines do not match maintenance parts quantity.', [
                    'maintenance_id' => (int) $sale->maintenance_id,
                    'product_id' => (int) $first->product_id,
                    'sale_quantity' => $expectedQuantity,
                    'parts_quantity' => $partQuantity,
                ]);

                continue;
            }

            $missingPartCost = $matchingParts->first(fn (MaintenanceProduct $part) => $part->inventory_total_cost === null);
            $partSnapshot = $missingPartCost ? null : (float) $matchingParts->sum('inventory_total_cost');
            $inspection = $this->inspectAllocationSet(
                'maintenance',
                (int) $sale->maintenance_id,
                (int) $first->product_id,
                $first->size_color_id ? (int) $first->size_color_id : null,
                $expectedQuantity,
                $partSnapshot,
                'maintenance',
                (int) $sale->maintenance_id,
            );
            $issues = array_merge($issues, $inspection['issues']);
            $totalCost += (float) $inspection['total_cost'];

            if ($inspection['repair'] && $missingPartCost) {
                $issues[] = $this->issue('ambiguous_maintenance_snapshot', 'Maintenance part cost snapshot is missing and cannot be distributed safely.', [
                    'maintenance_id' => (int) $sale->maintenance_id,
                    'product_id' => (int) $first->product_id,
                ]);

                continue;
            }

            if (! $missingPartCost && $lines->count() === $matchingParts->count()) {
                foreach ($lines->values() as $index => $line) {
                    $part = $matchingParts[$index];
                    if ($line->inventory_total_cost === null
                        && abs((float) $line->quantity - (float) $part->quantity) <= self::EPSILON) {
                        $repairs[] = [
                            'table' => 'instant_sales',
                            'id' => (int) $line->id,
                            'method' => $part->inventory_cost_method ?: 'fifo',
                            'unit_cost' => (float) $part->inventory_unit_cost,
                            'total_cost' => (float) $part->inventory_total_cost,
                        ];
                        $issues[] = $this->issue('missing_snapshot', 'Instant-sale snapshot is missing but maintenance FIFO evidence is complete.', [
                            'instant_sale_id' => (int) $line->id,
                        ]);
                    } elseif ($line->inventory_total_cost !== null
                        && abs((float) $line->inventory_total_cost - (float) $part->inventory_total_cost) > self::EPSILON) {
                        $issues[] = $this->issue('snapshot_mismatch', 'Instant-sale and maintenance part cost snapshots differ.', [
                            'instant_sale_id' => (int) $line->id,
                            'maintenance_product_id' => (int) $part->id,
                        ]);
                    }
                }
            }
        }

        return $this->result($issues, $repairs, $totalCost, true);
    }

    /** @return array<string, mixed> */
    private function inspectAllocationSet(
        string $referenceType,
        int $referenceId,
        int $productId,
        ?int $sizeColorId,
        float $expectedQuantity,
        ?float $snapshotTotal,
        string $sourceType,
        int $sourceId,
        bool $requireSnapshot = true,
    ): array {
        $issues = [];
        if (! Schema::hasTable('inventory_cost_allocations') || ! Schema::hasTable('inventory_cost_layers')) {
            return [
                'issues' => [$this->issue('missing_cost_table', 'Inventory cost allocation/layer tables are unavailable.', compact('sourceType', 'sourceId'))],
                'repair' => null,
                'total_cost' => 0.0,
            ];
        }

        $allocations = InventoryCostAllocation::query()
            ->where('reference_type', $referenceType)
            ->where('reference_id', $referenceId)
            ->where('product_id', $productId)
            ->when($sizeColorId !== null,
                fn ($query) => $query->where('size_color_id', $sizeColorId),
                fn ($query) => $query->whereNull('size_color_id'))
            ->orderBy('id')
            ->get();
        $allocatedQuantity = (float) $allocations->sum('quantity');
        $allocatedCost = round((float) $allocations->sum('total_cost'), 6);
        $context = [
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'product_id' => $productId,
            'size_color_id' => $sizeColorId,
            'sold_quantity' => $expectedQuantity,
            'allocated_quantity' => $allocatedQuantity,
        ];

        if ($allocations->isEmpty() || $allocatedQuantity + self::EPSILON < $expectedQuantity) {
            $issues[] = $this->issue('allocation_shortage', 'FIFO allocations do not cover the sold quantity.', $context);
        }
        if ($allocatedQuantity - self::EPSILON > $expectedQuantity) {
            $issues[] = $this->issue('allocation_exceeds_quantity', 'FIFO allocated quantity exceeds the sold quantity.', $context);
        }

        $pending = $allocations->whereNull('inventory_cost_layer_id');
        if ($pending->isNotEmpty()) {
            $issues[] = $this->issue('pending_negative_cost', 'FIFO cost is pending because one or more allocations have no source layer.', array_merge($context, [
                'pending_quantity' => (float) $pending->sum('quantity'),
            ]));
        }

        $layerIds = $allocations->pluck('inventory_cost_layer_id')->filter()->unique()->values();
        $layers = InventoryCostLayer::query()->whereIn('id', $layerIds)->get()->keyBy('id');
        foreach ($allocations as $allocation) {
            if (abs((float) $allocation->total_cost - ((float) $allocation->quantity * (float) $allocation->unit_cost)) > 0.001) {
                $issues[] = $this->issue('allocation_value_mismatch', 'FIFO allocation total does not equal quantity multiplied by unit cost.', array_merge($context, [
                    'allocation_id' => (int) $allocation->id,
                ]));
            }
            if (! $allocation->inventory_cost_layer_id) {
                continue;
            }
            $layer = $layers->get($allocation->inventory_cost_layer_id);
            if (! $layer
                || (int) $layer->product_id !== $productId
                || (int) ($layer->size_color_id ?? 0) !== (int) ($sizeColorId ?? 0)) {
                $issues[] = $this->issue('invalid_cost_layer', 'FIFO allocation is not linked to the matching product/variant layer.', array_merge($context, [
                    'allocation_id' => (int) $allocation->id,
                    'layer_id' => $allocation->inventory_cost_layer_id,
                ]));
            } elseif ((float) $layer->remaining_quantity < -self::EPSILON) {
                $issues[] = $this->issue('negative_cost_layer', 'FIFO layer has a negative remaining quantity.', array_merge($context, [
                    'layer_id' => (int) $layer->id,
                    'remaining_quantity' => (float) $layer->remaining_quantity,
                ]));
            }
        }

        if (Schema::hasTable('product_stock_movements')) {
            $movements = ProductStockMovement::query()
                ->where('reference_type', $referenceType)
                ->where('reference_id', $referenceId)
                ->where('product_id', $productId)
                ->when($sizeColorId !== null,
                    fn ($query) => $query->where('size_color_id', $sizeColorId),
                    fn ($query) => $query->whereNull('size_color_id'))
                ->where('quantity', '<', 0)
                ->orderBy('id')
                ->get();
            if ($movements->isEmpty()) {
                $issues[] = $this->issue('missing_stock_movement', 'No outbound stock movement exists for the cost allocation.', $context);
            } elseif ($movements->contains(fn (ProductStockMovement $movement) => $movement->total_cost === null)) {
                $issues[] = $this->issue('missing_movement_cost', 'Outbound stock movement has no reliable total cost.', array_merge($context, [
                    'movement_ids' => $movements->whereNull('total_cost')->pluck('id')->map(fn ($id) => (int) $id)->all(),
                ]));
            } else {
                $movementQuantity = abs((float) $movements->sum('quantity'));
                $movementTotalCost = (float) $movements->sum('total_cost');
                if (abs($movementQuantity - $expectedQuantity) > self::EPSILON) {
                    $issues[] = $this->issue('movement_quantity_mismatch', 'Outbound stock movement quantity does not match the operational quantity.', array_merge($context, [
                        'movement_ids' => $movements->pluck('id')->map(fn ($id) => (int) $id)->all(),
                        'movement_quantity' => $movementQuantity,
                    ]));
                }
                if (abs($movementTotalCost - $allocatedCost) > 0.001) {
                    $issues[] = $this->issue('movement_cost_mismatch', 'Outbound stock movement cost does not match FIFO allocations.', array_merge($context, [
                        'movement_ids' => $movements->pluck('id')->map(fn ($id) => (int) $id)->all(),
                        'movement_total_cost' => $movementTotalCost,
                        'allocated_total_cost' => $allocatedCost,
                    ]));
                }
            }
        }

        $reliableAllocations = $issues === [];
        $repair = null;
        if ($requireSnapshot && $snapshotTotal === null) {
            $issues[] = $this->issue('missing_snapshot', 'Inventory total cost snapshot is missing.', $context);
            if ($reliableAllocations && $expectedQuantity > self::EPSILON) {
                $repair = [
                    'method' => (string) ($allocations->first()?->method ?: 'fifo'),
                    'unit_cost' => round($allocatedCost / $expectedQuantity, 6),
                    'total_cost' => $allocatedCost,
                ];
            }
        } elseif ($snapshotTotal !== null && abs($snapshotTotal - $allocatedCost) > 0.001) {
            $issues[] = $this->issue('snapshot_mismatch', 'Inventory snapshot does not match FIFO allocations.', array_merge($context, [
                'snapshot_total' => $snapshotTotal,
                'allocated_total' => $allocatedCost,
            ]));
        }

        return [
            'issues' => $issues,
            'repair' => $repair,
            'total_cost' => $snapshotTotal ?? $allocatedCost,
        ];
    }

    /** @param array<int, array<string, mixed>> $issues @param array<int, array<string, mixed>> $repairs */
    private function result(array $issues, array $repairs, float $totalCost, bool $requiresCost): array
    {
        $blocking = collect($issues)->reject(fn (array $issue) => $issue['code'] === 'missing_snapshot'
            && collect($repairs)->contains(fn (array $repair) => (int) ($repair['id'] ?? 0) === (int) ($issue['context']['instant_sale_id'] ?? $issue['context']['source_id'] ?? 0)));

        return [
            'ready' => $issues === [],
            'repairable' => $issues !== [] && $blocking->isEmpty() && $repairs !== [],
            'requires_cost' => $requiresCost,
            'total_cost' => round($totalCost, 6),
            'issues' => $issues,
            'repairs' => $repairs,
        ];
    }

    /** @param array<string, mixed> $context @return array<string, mixed> */
    private function issue(string $code, string $message, array $context = []): array
    {
        return compact('code', 'message', 'context');
    }

    /** @param array<string, mixed> $inspection */
    private function failureMessage(array $inspection): string
    {
        return collect($inspection['issues'])
            ->map(fn (array $issue) => $issue['code'].': '.$issue['message'])
            ->implode(' | ');
    }

    private function identity(int $productId, mixed $sizeColorId): string
    {
        return $productId.':'.((int) $sizeColorId ?: 'main');
    }
}
