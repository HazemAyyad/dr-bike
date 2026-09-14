<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\InventoryCostAllocation;
use App\Models\InventoryCostBalance;
use App\Models\InventoryCostLayer;
use App\Models\InventoryCostReview;
use App\Models\Product;
use App\Models\ProductStockMovement;
use App\Models\SizeColor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class InventoryCostingService
{
    public const METHOD_FIFO = 'fifo';

    public const METHOD_MOVING_AVERAGE = 'moving_average';

    private const EPSILON = 0.0001;

    public function __construct(private ProductStockService $stockService)
    {
    }

    public function currentMethod(): string
    {
        $value = AppSetting::query()
            ->where('key', AppSetting::KEY_INVENTORY_COSTING_METHOD)
            ->value('value');

        return $value === self::METHOD_MOVING_AVERAGE
            ? self::METHOD_MOVING_AVERAGE
            : self::METHOD_FIFO;
    }

    public function setMethod(string $method): void
    {
        $method = $method === self::METHOD_MOVING_AVERAGE
            ? self::METHOD_MOVING_AVERAGE
            : self::METHOD_FIFO;

        DB::transaction(function () use ($method) {
            $previous = $this->currentMethod();
            if ($previous === self::METHOD_MOVING_AVERAGE && $method === self::METHOD_FIFO
                && Schema::hasTable('inventory_cost_balances')) {
                $this->collapseMovingAverageBalancesToFifoLayers();
            }

            AppSetting::query()->updateOrCreate(
                ['key' => AppSetting::KEY_INVENTORY_COSTING_METHOD],
                ['value' => $method]
            );

            AppSetting::query()->updateOrCreate(
                ['key' => AppSetting::KEY_INVENTORY_COSTING_METHOD_EFFECTIVE_FROM],
                ['value' => now()->toDateTimeString()]
            );
        });
    }

    public function identityKey(int $productId, ?int $sizeColorId = null): string
    {
        return 'product:'.$productId.':variant:'.($sizeColorId ?: 'main');
    }

    public function normalizeCurrency(?string $currency): string
    {
        return match (mb_strtolower(trim((string) $currency))) {
            'nis', 'ils', '₪', 'شيكل', 'שקל' => 'شيكل',
            'usd', '$', 'دولار' => 'دولار',
            'jod', 'دينار' => 'دينار',
            default => trim((string) $currency) ?: 'شيكل',
        };
    }

    public function addOwnedStock(
        Product $product,
        float $quantity,
        float $unitCost,
        string $currency,
        string $sourceType,
        ?int $sourceId,
        ?int $sizeColorId = null,
        ?int $sizeId = null,
        ?int $userId = null,
        ?string $note = null,
        string $movementType = ProductStockMovement::TYPE_PURCHASE,
        ?string $reason = null,
        ?string $idempotencyKey = null,
        ?string $movementReferenceType = null,
        ?int $movementReferenceId = null,
    ): InventoryCostLayer {
        $currency = $this->normalizeCurrency($currency);
        $stockQuantity = $this->wholeStockQuantity($quantity);
        if ($unitCost < 0) {
            throw new \InvalidArgumentException('Unit cost cannot be negative.');
        }

        return DB::transaction(function () use ($product, $quantity, $stockQuantity, $unitCost, $currency, $sourceType, $sourceId, $sizeColorId, $sizeId, $userId, $note, $movementType, $reason, $idempotencyKey, $movementReferenceType, $movementReferenceId) {
            $lockedProduct = Product::withTrashed()->lockForUpdate()->findOrFail($product->id);
            $sizeId = $this->validateAndResolveSizeId($lockedProduct, $sizeColorId, $sizeId);

            if ($idempotencyKey !== null) {
                $existing = InventoryCostLayer::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();
                if ($existing instanceof InventoryCostLayer) {
                    return $existing;
                }
            }

            $this->assertCurrencyCompatible($lockedProduct, $currency);

            $balance = $this->lockedBalance($lockedProduct, $sizeColorId, $sizeId, $currency);
            $physicalBefore = $this->physicalQuantity($lockedProduct, $sizeColorId);
            $negativeQuantityToSettle = min($quantity, max(0, -$physicalBefore));
            $addedValue = round($quantity * $unitCost, 6);
            $newQuantity = round($physicalBefore + $quantity, 4);

            $layer = InventoryCostLayer::create([
                'product_id' => $lockedProduct->id,
                'size_id' => $sizeId,
                'size_color_id' => $sizeColorId,
                'quantity' => $quantity,
                // Units that settle an earlier negative sale were already handed
                // to the customer and therefore are not remaining inventory.
                'remaining_quantity' => max(0, round($quantity - $negativeQuantityToSettle, 4)),
                'unit_cost' => $unitCost,
                'original_unit_cost' => $unitCost,
                'currency' => $currency,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'idempotency_key' => $idempotencyKey,
                'effective_at' => now(),
            ]);

            $resolvedReferences = $this->settlePendingNegativeAllocations(
                $lockedProduct,
                $layer,
                $negativeQuantityToSettle,
                $sizeColorId,
                $sizeId,
                $unitCost,
            );

            $remainingLayerValue = (float) $this->layerIdentityQuery((int) $lockedProduct->id, $sizeColorId)
                ->where('remaining_quantity', '>', 0)
                ->selectRaw('COALESCE(SUM(remaining_quantity * unit_cost), 0) as value')
                ->value('value');
            $pendingNegativeValue = (float) InventoryCostAllocation::query()
                ->where('product_id', $lockedProduct->id)
                ->whereNull('inventory_cost_layer_id')
                ->when($sizeColorId !== null && $sizeColorId > 0,
                    fn ($query) => $query->where('size_color_id', $sizeColorId),
                    fn ($query) => $query->whereNull('size_color_id'))
                ->sum('total_cost');
            $newValue = round($remainingLayerValue - $pendingNegativeValue, 6);
            $newAverage = abs($newQuantity) > self::EPSILON
                ? abs($newValue / $newQuantity)
                : 0.0;
            $hasPendingNegativeCost = InventoryCostAllocation::query()
                ->where('product_id', $lockedProduct->id)
                ->whereNull('inventory_cost_layer_id')
                ->when($sizeColorId !== null && $sizeColorId > 0,
                    fn ($query) => $query->where('size_color_id', $sizeColorId),
                    fn ($query) => $query->whereNull('size_color_id'))
                ->exists();

            $balance->update([
                'quantity' => $newQuantity,
                'inventory_value' => $newValue,
                'moving_average_unit_cost' => $newAverage,
                'currency' => $currency,
                'needs_review' => $hasPendingNegativeCost,
                'review_reason' => $hasPendingNegativeCost ? 'negative_stock_cost_pending' : null,
            ]);

            $this->stockService->adjustStock(
                product: $lockedProduct,
                quantityDelta: $stockQuantity,
                type: $movementType,
                sizeColorId: $sizeColorId,
                referenceType: $movementReferenceType ?? $sourceType,
                referenceId: $movementReferenceId ?? $sourceId,
                note: $note,
                userId: $userId,
                unitCost: $unitCost,
                totalCost: $addedValue,
                costingMethod: $this->currentMethod(),
                reason: $reason,
            );

            $this->refreshResolvedOutboundSnapshots($resolvedReferences);

            return $layer;
        });
    }

    /**
     * @return array{method: string, total_cost: float, unit_cost: float, allocations: array<int, InventoryCostAllocation>}
     */
    public function consumeCost(
        Product $product,
        float $quantity,
        string $referenceType,
        ?int $referenceId,
        ?int $sizeColorId = null,
        ?int $sizeId = null,
        bool $allowNegative = false,
    ): array {
        if ($quantity <= 0) {
            return ['method' => $this->currentMethod(), 'total_cost' => 0.0, 'unit_cost' => 0.0, 'allocations' => []];
        }

        $this->wholeStockQuantity($quantity);

        return DB::transaction(function () use ($product, $quantity, $referenceType, $referenceId, $sizeColorId, $sizeId, $allowNegative) {
            $lockedProduct = Product::withTrashed()->lockForUpdate()->findOrFail($product->id);
            $sizeId = $this->validateAndResolveSizeId($lockedProduct, $sizeColorId, $sizeId);
            $method = $this->currentMethod();
            $balance = $this->lockedBalance($lockedProduct, $sizeColorId, $sizeId);

            $layers = $this->layerIdentityQuery((int) $lockedProduct->id, $sizeColorId)
                ->where('remaining_quantity', '>', 0)
                ->orderBy('effective_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $available = (float) $layers->sum('remaining_quantity');
            if (! $allowNegative && ($available + self::EPSILON < $quantity || (float) $balance->quantity + self::EPSILON < $quantity)) {
                $this->markCoverageReview($lockedProduct, $sizeColorId, $sizeId, $this->physicalQuantity($lockedProduct, $sizeColorId), $available);
                throw ValidationException::withMessages([
                    'inventory' => ['كمية المخزون غير مغطاة بالكامل بتكلفة محاسبية. يجب معالجة مراجعة تكلفة المخزون أولاً.'],
                ]);
            }

            $coveredQuantity = $allowNegative
                ? min($quantity, max(0, $available), max(0, (float) $balance->quantity))
                : $quantity;
            $movingAverage = $method === self::METHOD_MOVING_AVERAGE
                ? (float) $balance->moving_average_unit_cost
                : null;
            $remaining = $coveredQuantity;
            $totalCost = 0.0;
            $allocations = [];

            foreach ($layers as $layer) {
                if ($remaining <= self::EPSILON) {
                    break;
                }

                $take = min((float) $layer->remaining_quantity, $remaining);
                $cost = $movingAverage ?? (float) $layer->unit_cost;
                $lineTotal = round($take * $cost, 6);

                $layer->update([
                    'remaining_quantity' => max(0, round((float) $layer->remaining_quantity - $take, 4)),
                ]);

                $allocations[] = InventoryCostAllocation::create([
                    'inventory_cost_layer_id' => $layer->id,
                    'product_id' => $lockedProduct->id,
                    'size_id' => $sizeId,
                    'size_color_id' => $sizeColorId,
                    'quantity' => $take,
                    'unit_cost' => $cost,
                    'total_cost' => $lineTotal,
                    'method' => $method,
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceId,
                ]);

                $remaining -= $take;
                $totalCost += $lineTotal;
            }

            $uncoveredQuantity = max(0, round($quantity - $coveredQuantity, 4));
            $pendingCostKnown = true;
            if ($uncoveredQuantity > self::EPSILON) {
                $fallbackUnitCost = $this->negativeStockFallbackUnitCost(
                    $lockedProduct,
                    $sizeColorId,
                    $method,
                    $balance,
                );
                $pendingCostKnown = $fallbackUnitCost !== null;
                $fallbackUnitCost ??= 0.0;
                $pendingTotal = round($uncoveredQuantity * $fallbackUnitCost, 6);
                $allocations[] = InventoryCostAllocation::create([
                    'inventory_cost_layer_id' => null,
                    'product_id' => $lockedProduct->id,
                    'size_id' => $sizeId,
                    'size_color_id' => $sizeColorId,
                    'quantity' => $uncoveredQuantity,
                    'unit_cost' => $fallbackUnitCost,
                    'total_cost' => $pendingTotal,
                    'method' => $method,
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceId,
                ]);
                $totalCost += $pendingTotal;
            }

            $newQuantity = round((float) $balance->quantity - $quantity, 4);
            $newValue = round((float) $balance->inventory_value - $totalCost, 6);
            $balance->update([
                'quantity' => $newQuantity,
                'inventory_value' => abs($newQuantity) > self::EPSILON ? $newValue : 0,
                // A sale never changes the perpetual moving-average unit cost.
                'moving_average_unit_cost' => $method === self::METHOD_MOVING_AVERAGE
                    ? (abs($newQuantity) > self::EPSILON ? ((float) $balance->moving_average_unit_cost ?: ($pendingCostKnown ? ($quantity > 0 ? $totalCost / $quantity : 0) : 0)) : 0)
                    : (abs($newQuantity) > self::EPSILON ? abs($newValue / $newQuantity) : 0),
                'needs_review' => $uncoveredQuantity > self::EPSILON,
                'review_reason' => $uncoveredQuantity > self::EPSILON ? 'negative_stock_cost_pending' : null,
            ]);

            return [
                'method' => $method,
                'total_cost' => round($totalCost, 6),
                'unit_cost' => $quantity > 0 ? round($totalCost / $quantity, 6) : 0.0,
                'allocations' => $allocations,
                'cost_complete' => $uncoveredQuantity <= self::EPSILON,
                'pending_quantity' => $uncoveredQuantity,
                'provisional_cost_known' => $pendingCostKnown,
            ];
        });
    }

    /**
     * @return array{method: string, total_cost: float, unit_cost: float, allocations: array<int, InventoryCostAllocation>}
     */
    public function consumeOwnedStock(
        Product $product,
        float $quantity,
        string $movementType,
        string $referenceType,
        ?int $referenceId,
        ?int $sizeColorId = null,
        ?int $sizeId = null,
        ?int $userId = null,
        ?string $note = null,
        ?string $reason = null,
    ): array {
        $stockQuantity = $this->wholeStockQuantity($quantity);

        return DB::transaction(function () use ($product, $quantity, $stockQuantity, $movementType, $referenceType, $referenceId, $sizeColorId, $sizeId, $userId, $note, $reason) {
            $cost = $this->consumeCost($product, $quantity, $referenceType, $referenceId, $sizeColorId, $sizeId);

            $this->stockService->adjustStock(
                product: $product,
                quantityDelta: -$stockQuantity,
                type: $movementType,
                sizeColorId: $sizeColorId,
                referenceType: $referenceType,
                referenceId: $referenceId,
                note: $note,
                userId: $userId,
                unitCost: $cost['unit_cost'],
                totalCost: $cost['total_cost'],
                costingMethod: $cost['method'],
                reason: $reason,
            );

            return $cost;
        });
    }

    /**
     * Remove unresolved negative-cost allocations when their outbound event is
     * reversed before a later receipt supplied their cost.
     *
     * @return array{quantity:float,total_cost:float,method:string}
     */
    public function reversePendingNegativeCost(
        Product $product,
        float $quantity,
        string $referenceType,
        ?int $referenceId,
        ?int $sizeColorId = null,
        ?int $sizeId = null,
    ): array {
        if ($quantity <= self::EPSILON || ! $referenceId) {
            return ['quantity' => 0.0, 'total_cost' => 0.0, 'method' => $this->currentMethod()];
        }

        return DB::transaction(function () use ($product, $quantity, $referenceType, $referenceId, $sizeColorId, $sizeId) {
            $product = Product::withTrashed()->lockForUpdate()->findOrFail($product->id);
            $sizeId = $this->validateAndResolveSizeId($product, $sizeColorId, $sizeId);
            $balance = $this->lockedBalance($product, $sizeColorId, $sizeId);
            $rows = InventoryCostAllocation::query()
                ->where('product_id', $product->id)
                ->where('reference_type', $referenceType)
                ->where('reference_id', $referenceId)
                ->whereNull('inventory_cost_layer_id')
                ->when($sizeColorId !== null && $sizeColorId > 0,
                    fn ($query) => $query->where('size_color_id', $sizeColorId),
                    fn ($query) => $query->whereNull('size_color_id'))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $remaining = $quantity;
            $removedQuantity = 0.0;
            $removedCost = 0.0;
            $method = (string) ($rows->first()?->method ?: $this->currentMethod());
            foreach ($rows as $row) {
                if ($remaining <= self::EPSILON) {
                    break;
                }
                $take = min((float) $row->quantity, $remaining);
                $takeCost = round($take * (float) $row->unit_cost, 6);
                if ($take + self::EPSILON >= (float) $row->quantity) {
                    $row->delete();
                } else {
                    $left = round((float) $row->quantity - $take, 4);
                    $row->update([
                        'quantity' => $left,
                        'total_cost' => round($left * (float) $row->unit_cost, 6),
                    ]);
                }
                $remaining -= $take;
                $removedQuantity += $take;
                $removedCost += $takeCost;
            }

            if ($removedQuantity > self::EPSILON) {
                $newQuantity = round((float) $balance->quantity + $removedQuantity, 4);
                $newValue = round((float) $balance->inventory_value + $removedCost, 6);
                $hasPending = InventoryCostAllocation::query()
                    ->where('product_id', $product->id)
                    ->whereNull('inventory_cost_layer_id')
                    ->when($sizeColorId !== null && $sizeColorId > 0,
                        fn ($query) => $query->where('size_color_id', $sizeColorId),
                        fn ($query) => $query->whereNull('size_color_id'))
                    ->exists();
                $balance->update([
                    'quantity' => $newQuantity,
                    'inventory_value' => abs($newQuantity) > self::EPSILON ? $newValue : 0,
                    'moving_average_unit_cost' => abs($newQuantity) > self::EPSILON ? abs($newValue / $newQuantity) : 0,
                    'needs_review' => $hasPending,
                    'review_reason' => $hasPending ? 'negative_stock_cost_pending' : null,
                ]);
            }

            return [
                'quantity' => round($removedQuantity, 4),
                'total_cost' => round($removedCost, 6),
                'method' => $method,
            ];
        });
    }

    /** Consume a quantity from one explicitly selected FIFO layer. */
    public function consumeOwnedStockFromLayer(
        Product $product,
        InventoryCostLayer $selectedLayer,
        float $quantity,
        string $movementType,
        string $referenceType,
        ?int $referenceId,
        ?int $userId = null,
        ?string $note = null,
    ): array {
        $stockQuantity = $this->wholeStockQuantity($quantity);

        return DB::transaction(function () use ($product, $selectedLayer, $quantity, $stockQuantity, $movementType, $referenceType, $referenceId, $userId, $note) {
            if ($this->currentMethod() !== self::METHOD_FIFO) {
                throw ValidationException::withMessages([
                    'cost_layer_id' => ['اختيار طبقة تكلفة محددة متاح فقط عند استخدام FIFO.'],
                ]);
            }

            $lockedProduct = Product::withTrashed()->lockForUpdate()->findOrFail($product->id);
            $layer = InventoryCostLayer::query()->lockForUpdate()->findOrFail($selectedLayer->id);
            if ((int) $layer->product_id !== (int) $lockedProduct->id || (float) $layer->remaining_quantity + self::EPSILON < $quantity) {
                throw new \RuntimeException('الكمية المطلوبة غير متاحة ضمن طبقة التكلفة المختارة.');
            }
            $sizeId = $this->validateAndResolveSizeId($lockedProduct, $layer->size_color_id, $layer->size_id);
            $balance = $this->lockedBalance($lockedProduct, $layer->size_color_id, $sizeId);

            $unitCost = (float) $layer->unit_cost;
            $totalCost = round($quantity * $unitCost, 6);
            $layer->update(['remaining_quantity' => max(0, round((float) $layer->remaining_quantity - $quantity, 4))]);

            $allocation = InventoryCostAllocation::create([
                'inventory_cost_layer_id' => $layer->id,
                'product_id' => $lockedProduct->id,
                'size_id' => $sizeId,
                'size_color_id' => $layer->size_color_id,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'total_cost' => $totalCost,
                'method' => self::METHOD_FIFO,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
            ]);

            $newQuantity = max(0, round((float) $balance->quantity - $quantity, 4));
            $newValue = max(0, round((float) $balance->inventory_value - $totalCost, 6));
            $balance->update([
                'quantity' => $newQuantity,
                'inventory_value' => $newQuantity > self::EPSILON ? $newValue : 0,
                'moving_average_unit_cost' => $newQuantity > self::EPSILON ? $newValue / $newQuantity : 0,
            ]);

            $this->stockService->adjustStock(
                product: $lockedProduct,
                quantityDelta: -$stockQuantity,
                type: $movementType,
                sizeColorId: $layer->size_color_id,
                referenceType: $referenceType,
                referenceId: $referenceId,
                note: $note,
                userId: $userId,
                unitCost: $unitCost,
                totalCost: $totalCost,
                costingMethod: self::METHOD_FIFO,
            );

            return [
                'method' => self::METHOD_FIFO,
                'total_cost' => $totalCost,
                'unit_cost' => $unitCost,
                'allocations' => [$allocation],
            ];
        });
    }

    /**
     * Authoritative inventory information for a product. Cost fields can be hidden
     * without changing where they are calculated.
     *
     * @return array<string, mixed>
     */
    public function productSummary(Product $product, bool $includeCost = true, int $layerLimit = 50): array
    {
        $product->loadMissing('sizes.colorSizes');
        $method = $this->currentMethod();
        $variants = [];

        if ($this->stockService->productHasVariants($product)) {
            foreach ($product->sizes as $size) {
                foreach ($size->colorSizes as $variant) {
                    $row = $this->identitySummary($product, (int) $variant->id, (int) $size->id, $includeCost, $layerLimit);
                    $row['size_label'] = (string) $size->size;
                    $row['color_label'] = (string) $variant->colorAr;
                    $variants[] = $row;
                }
            }
        }

        if ($variants !== []) {
            $quantity = array_sum(array_column($variants, 'quantity_on_hand'));
            $costedQuantity = array_sum(array_column($variants, 'costed_quantity'));
            $missingCostQuantity = array_sum(array_column($variants, 'missing_cost_quantity'));
            $negativeQuantity = array_sum(array_column($variants, 'negative_quantity'));
            $pendingNegativeCostQuantity = array_sum(array_column($variants, 'pending_negative_cost_quantity'));
            $value = $includeCost ? array_sum(array_column($variants, 'inventory_value')) : null;
            $nextLayer = collect($variants)
                ->filter(fn (array $row) => isset($row['next_fifo_effective_at']) && $row['next_fifo_effective_at'])
                ->sortBy('next_fifo_effective_at')
                ->first();
            $currencies = collect($variants)
                ->filter(fn (array $row) => (float) $row['quantity_on_hand'] > self::EPSILON)
                ->pluck('currency')->filter()->unique()->values();
            $currency = $currencies->count() === 1 ? (string) $currencies->first() : 'mixed';
            $currencyConsistent = $currencies->count() <= 1;

            return [
                'quantity_on_hand' => $quantity,
                'costed_quantity' => $costedQuantity,
                'missing_cost_quantity' => round($missingCostQuantity, 4),
                'negative_quantity' => round($negativeQuantity, 4),
                'pending_negative_cost_quantity' => round($pendingNegativeCostQuantity, 4),
                'costing_method' => $method,
                'currency' => $currency,
                'inventory_value' => $includeCost ? round((float) $value, 6) : null,
                'average_inventory_unit_cost' => $includeCost && $quantity > self::EPSILON ? round((float) $value / $quantity, 6) : null,
                'next_fifo_unit_cost' => $includeCost && $method === self::METHOD_FIFO ? ($nextLayer['next_fifo_unit_cost'] ?? null) : null,
                'cost_coverage_complete' => $currencyConsistent
                    && collect($variants)->every(fn (array $row) => $row['cost_coverage_complete']),
                'has_variants' => true,
                'variants' => $variants,
                'cost_layers' => $includeCost ? collect($variants)->flatMap(fn (array $row) => $row['cost_layers'])->values()->all() : [],
            ];
        }

        return array_merge(
            $this->identitySummary($product, null, null, $includeCost, $layerLimit),
            ['has_variants' => false, 'variants' => []]
        );
    }

    /** @return array<string, mixed> */
    public function identitySummary(Product $product, ?int $sizeColorId, ?int $sizeId, bool $includeCost = true, int $layerLimit = 50): array
    {
        $method = $this->currentMethod();
        $physical = $this->physicalQuantity($product, $sizeColorId);
        $layersQuery = $this->layerIdentityQuery((int) $product->id, $sizeColorId)
            ->where('remaining_quantity', '>', 0);
        $costedQuantity = (float) (clone $layersQuery)->sum('remaining_quantity');
        $fifoValue = (float) (clone $layersQuery)->selectRaw('COALESCE(SUM(remaining_quantity * unit_cost), 0) as value')->value('value');
        $balance = Schema::hasTable('inventory_cost_balances')
            ? InventoryCostBalance::query()->where('identity_key', $this->identityKey((int) $product->id, $sizeColorId))->first()
            : null;
        $pendingNegativeCostQuantity = Schema::hasTable('inventory_cost_allocations')
            ? (float) InventoryCostAllocation::query()
                ->where('product_id', $product->id)
                ->whereNull('inventory_cost_layer_id')
                ->when($sizeColorId !== null && $sizeColorId > 0,
                    fn ($query) => $query->where('size_color_id', $sizeColorId),
                    fn ($query) => $query->whereNull('size_color_id'))
                ->sum('quantity')
            : 0.0;

        $value = (($method === self::METHOD_MOVING_AVERAGE || $physical < -self::EPSILON) && $balance)
            ? (float) $balance->inventory_value
            : $fifoValue;
        $average = abs($physical) > self::EPSILON ? abs($value / $physical) : 0.0;
        if ($method === self::METHOD_MOVING_AVERAGE && $balance) {
            $average = (float) $balance->moving_average_unit_cost;
            $costedQuantity = (float) $balance->quantity;
        }

        $next = (clone $layersQuery)->orderBy('effective_at')->orderBy('id')->first();
        $currency = $this->normalizeCurrency($balance?->currency ?? $next?->currency ?? 'شيكل');
        $layers = $includeCost
            ? (clone $layersQuery)->orderBy('effective_at')->orderBy('id')->limit($layerLimit)->get()->map(fn (InventoryCostLayer $layer) => [
                'id' => (int) $layer->id,
                'product_id' => (int) $layer->product_id,
                'size_id' => $layer->size_id ? (int) $layer->size_id : null,
                'size_color_id' => $layer->size_color_id ? (int) $layer->size_color_id : null,
                'quantity' => (float) $layer->quantity,
                'remaining_quantity' => (float) $layer->remaining_quantity,
                'unit_cost' => (float) $layer->unit_cost,
                'original_unit_cost' => (float) ($layer->original_unit_cost ?? $layer->unit_cost),
                'remaining_value' => round((float) $layer->remaining_quantity * (float) $layer->unit_cost, 6),
                'currency' => (string) $layer->currency,
                'source_type' => (string) $layer->source_type,
                'source_id' => $layer->source_id ? (int) $layer->source_id : null,
                'effective_at' => optional($layer->effective_at)->toIso8601String(),
            ])->all()
            : [];

        return [
            'product_id' => (int) $product->id,
            'size_id' => $sizeId,
            'size_color_id' => $sizeColorId,
            'quantity_on_hand' => $physical,
            'costed_quantity' => $costedQuantity,
            'missing_cost_quantity' => max(0, round($physical - $costedQuantity, 4)),
            'negative_quantity' => max(0, round(-$physical, 4)),
            'pending_negative_cost_quantity' => round($pendingNegativeCostQuantity, 4),
            'costing_method' => $method,
            'currency' => $currency,
            'inventory_value' => $includeCost ? round($value, 6) : null,
            'average_inventory_unit_cost' => $includeCost ? round($average, 6) : null,
            'next_fifo_unit_cost' => $includeCost && $method === self::METHOD_FIFO && $next ? (float) $next->unit_cost : null,
            'next_fifo_effective_at' => $includeCost && $method === self::METHOD_FIFO && $next ? optional($next->effective_at)->toIso8601String() : null,
            'cost_coverage_complete' => abs($physical - $costedQuantity) <= self::EPSILON
                && $pendingNegativeCostQuantity <= self::EPSILON,
            'cost_layers' => $layers,
        ];
    }

    /**
     * Adds accounting coverage for physical stock that predates the costing
     * engine. This deliberately does not change physical stock.
     */
    public function coverMissingCost(
        Product $product,
        float $unitCost,
        string $currency,
        string $sourceType,
        ?int $sourceId,
        ?int $sizeColorId = null,
        ?int $sizeId = null,
        ?int $userId = null,
        ?string $idempotencyKey = null,
    ): InventoryCostLayer {
        $currency = $this->normalizeCurrency($currency);
        if ($unitCost <= 0) {
            throw ValidationException::withMessages([
                'unit_cost' => ['تكلفة الوحدة يجب أن تكون أكبر من صفر.'],
            ]);
        }

        return DB::transaction(function () use ($product, $unitCost, $currency, $sourceType, $sourceId, $sizeColorId, $sizeId, $userId, $idempotencyKey) {
            $product = Product::withTrashed()->lockForUpdate()->findOrFail($product->id);
            $sizeId = $this->validateAndResolveSizeId($product, $sizeColorId, $sizeId);

            if ($idempotencyKey !== null) {
                $existing = InventoryCostLayer::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();
                if ($existing instanceof InventoryCostLayer) {
                    return $existing;
                }
            }

            $this->assertCurrencyCompatible($product, $currency);
            $before = $this->identitySummary($product, $sizeColorId, $sizeId, true, 0);
            $missingQuantity = round((float) $before['quantity_on_hand'] - (float) $before['costed_quantity'], 4);
            if ($missingQuantity <= self::EPSILON) {
                throw ValidationException::withMessages([
                    'inventory' => ['لا توجد كمية مخزون ناقصة التغطية لإضافة تكلفة لها.'],
                ]);
            }

            $balance = $this->lockedBalance($product, $sizeColorId, $sizeId, $currency);
            $oldQuantity = (float) $before['costed_quantity'];
            $oldValue = (float) ($before['inventory_value'] ?? 0);
            $addedValue = round($missingQuantity * $unitCost, 6);
            $newQuantity = round($oldQuantity + $missingQuantity, 4);
            $newValue = round($oldValue + $addedValue, 6);

            $earliestLayerAt = $this->layerIdentityQuery((int) $product->id, $sizeColorId)
                ->where('remaining_quantity', '>', 0)
                ->orderBy('effective_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->value('effective_at');
            $effectiveAt = $earliestLayerAt
                ? \Illuminate\Support\Carbon::parse($earliestLayerAt)->subSecond()
                : ($product->created_at ?? now());

            $layer = InventoryCostLayer::query()->create([
                'product_id' => $product->id,
                'size_id' => $sizeId,
                'size_color_id' => $sizeColorId,
                'quantity' => $missingQuantity,
                'remaining_quantity' => $missingQuantity,
                'unit_cost' => $unitCost,
                'original_unit_cost' => $unitCost,
                'currency' => $currency,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'idempotency_key' => $idempotencyKey,
                'effective_at' => $effectiveAt,
            ]);

            $balance->update([
                'quantity' => $newQuantity,
                'inventory_value' => $newValue,
                'moving_average_unit_cost' => $newQuantity > self::EPSILON ? $newValue / $newQuantity : 0,
                'currency' => $currency,
                'needs_review' => false,
                'review_reason' => null,
            ]);

            InventoryCostReview::query()
                ->where('identity_key', $this->identityKey((int) $product->id, $sizeColorId))
                ->update([
                    'physical_quantity' => $before['quantity_on_hand'],
                    'costed_quantity' => $newQuantity,
                    'missing_quantity' => 0,
                    'status' => 'resolved',
                    'resolved_by' => $userId,
                    'resolved_at' => now(),
                ]);

            return $layer;
        });
    }

    private function negativeStockFallbackUnitCost(
        Product $product,
        ?int $sizeColorId,
        string $method,
        InventoryCostBalance $balance,
    ): ?float {
        if ($method === self::METHOD_MOVING_AVERAGE && (float) $balance->moving_average_unit_cost > self::EPSILON) {
            return (float) $balance->moving_average_unit_cost;
        }

        $lastLayer = $this->layerIdentityQuery((int) $product->id, $sizeColorId)
            ->orderByDesc('effective_at')
            ->orderByDesc('id')
            ->first();
        if ($lastLayer instanceof InventoryCostLayer) {
            $cost = (float) ($lastLayer->original_unit_cost ?? $lastLayer->unit_cost);
            if ($cost > self::EPSILON) {
                return $cost;
            }
        }

        return null;
    }

    /**
     * Attach an incoming layer to unresolved negative-sale allocations. The
     * allocation keeps the original sale reference, while the receipt layer
     * supplies the final accounting cost.
     *
     * @return array<int, array{type:string,id:int,product_id:int,size_color_id:?int}>
     */
    private function settlePendingNegativeAllocations(
        Product $product,
        InventoryCostLayer $layer,
        float $quantity,
        ?int $sizeColorId,
        ?int $sizeId,
        float $unitCost,
    ): array {
        if ($quantity <= self::EPSILON) {
            return [];
        }

        $pending = InventoryCostAllocation::query()
            ->where('product_id', $product->id)
            ->whereNull('inventory_cost_layer_id')
            ->when($sizeColorId !== null && $sizeColorId > 0,
                fn ($query) => $query->where('size_color_id', $sizeColorId),
                fn ($query) => $query->whereNull('size_color_id'))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $remaining = $quantity;
        $references = [];
        foreach ($pending as $allocation) {
            if ($remaining <= self::EPSILON) {
                break;
            }

            $take = min((float) $allocation->quantity, $remaining);
            $resolvedTotal = round($take * $unitCost, 6);
            $references[] = [
                'type' => (string) $allocation->reference_type,
                'id' => (int) $allocation->reference_id,
                'product_id' => (int) $allocation->product_id,
                'size_color_id' => $allocation->size_color_id !== null ? (int) $allocation->size_color_id : null,
            ];

            if ($take + self::EPSILON >= (float) $allocation->quantity) {
                $allocation->update([
                    'inventory_cost_layer_id' => $layer->id,
                    'unit_cost' => $unitCost,
                    'total_cost' => $resolvedTotal,
                ]);
            } else {
                $unresolvedQuantity = round((float) $allocation->quantity - $take, 4);
                $allocation->update([
                    'quantity' => $unresolvedQuantity,
                    'total_cost' => round($unresolvedQuantity * (float) $allocation->unit_cost, 6),
                ]);
                InventoryCostAllocation::query()->create([
                    'inventory_cost_layer_id' => $layer->id,
                    'product_id' => $product->id,
                    'size_id' => $sizeId,
                    'size_color_id' => $sizeColorId,
                    'quantity' => $take,
                    'unit_cost' => $unitCost,
                    'total_cost' => $resolvedTotal,
                    'method' => $allocation->method,
                    'reference_type' => $allocation->reference_type,
                    'reference_id' => $allocation->reference_id,
                ]);
            }

            $remaining -= $take;
        }

        return collect($references)
            ->filter(fn (array $row) => $row['id'] > 0)
            ->unique(fn (array $row) => implode(':', [
                $row['type'],
                $row['id'],
                $row['product_id'],
                $row['size_color_id'] ?? 'base',
            ]))
            ->values()
            ->all();
    }

    /** @param array<int, array{type:string,id:int,product_id:int,size_color_id:?int}> $references */
    private function refreshResolvedOutboundSnapshots(array $references): void
    {
        foreach ($references as $reference) {
            $type = $reference['type'];
            $id = $reference['id'];
            $query = InventoryCostAllocation::query()
                ->where('reference_type', $type)
                ->where('reference_id', $id)
                ->where('product_id', $reference['product_id'])
                ->when($reference['size_color_id'] !== null,
                    fn ($query) => $query->where('size_color_id', $reference['size_color_id']),
                    fn ($query) => $query->whereNull('size_color_id'));
            if ((clone $query)->whereNull('inventory_cost_layer_id')->exists()) {
                continue;
            }

            $quantity = (float) (clone $query)->sum('quantity');
            $total = (float) (clone $query)->sum('total_cost');
            $method = (string) ((clone $query)->value('method') ?: $this->currentMethod());
            $unit = $quantity > self::EPSILON ? round($total / $quantity, 6) : 0.0;

            ProductStockMovement::query()
                ->where('reference_type', $type)
                ->where('reference_id', $id)
                ->where('product_id', $reference['product_id'])
                ->when($reference['size_color_id'] !== null,
                    fn ($query) => $query->where('size_color_id', $reference['size_color_id']),
                    fn ($query) => $query->whereNull('size_color_id'))
                ->where('quantity', '<', 0)
                ->update([
                    'unit_cost' => $unit,
                    'total_cost' => round($total, 6),
                    'costing_method' => $method,
                ]);

            if ($type === 'instant_sale' && Schema::hasTable('instant_sales') && Schema::hasColumn('instant_sales', 'inventory_total_cost')) {
                DB::table('instant_sales')->where('id', $id)->update([
                    'inventory_cost_method' => $method,
                    'inventory_unit_cost' => $unit,
                    'inventory_total_cost' => round($total, 6),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function lockedBalance(Product $product, ?int $sizeColorId = null, ?int $sizeId = null, string $currency = 'شيكل'): InventoryCostBalance
    {
        $currency = $this->normalizeCurrency($currency);
        $key = $this->identityKey((int) $product->id, $sizeColorId);
        $balance = InventoryCostBalance::query()->where('identity_key', $key)->lockForUpdate()->first();
        if ($balance instanceof InventoryCostBalance) {
            return $balance;
        }

        $layers = $this->layerIdentityQuery((int) $product->id, $sizeColorId)
            ->where('remaining_quantity', '>', 0)
            ->lockForUpdate()
            ->get();
        $quantity = (float) $layers->sum('remaining_quantity');
        $value = (float) $layers->sum(fn (InventoryCostLayer $layer) => (float) $layer->remaining_quantity * (float) $layer->unit_cost);
        $physical = $this->physicalQuantity($product, $sizeColorId);
        $needsReview = abs($physical - $quantity) > self::EPSILON;

        $balance = InventoryCostBalance::query()->create([
            'product_id' => $product->id,
            'size_id' => $sizeId,
            'size_color_id' => $sizeColorId,
            'identity_key' => $key,
            'quantity' => $quantity,
            'inventory_value' => $value,
            'moving_average_unit_cost' => $quantity > self::EPSILON ? $value / $quantity : 0,
            'currency' => $layers->first()?->currency ?? $currency,
            'needs_review' => $needsReview,
            'review_reason' => $needsReview ? 'physical_and_cost_quantity_mismatch' : null,
        ]);

        if ($needsReview) {
            $this->markCoverageReview($product, $sizeColorId, $sizeId, $physical, $quantity);
        }

        return $balance;
    }

    public function assertCurrencyCompatible(Product $product, string $currency): void
    {
        $currency = $this->normalizeCurrency($currency);
        $currencies = InventoryCostBalance::query()
            ->where('product_id', $product->id)
            ->where('quantity', '>', self::EPSILON)
            ->pluck('currency')
            ->concat(InventoryCostLayer::query()
                ->where('product_id', $product->id)
                ->where('remaining_quantity', '>', self::EPSILON)
                ->pluck('currency'))
            ->map(fn ($value) => $this->normalizeCurrency((string) $value))
            ->unique();

        if ($currencies->isNotEmpty() && ($currencies->count() > 1 || ! $currencies->contains($currency))) {
            throw ValidationException::withMessages([
                'currency' => ['لا يمكن جمع تكاليف مخزون بعملات مختلفة دون سعر تحويل محاسبي موثق.'],
            ]);
        }
    }

    private function layerIdentityQuery(int $productId, ?int $sizeColorId): Builder
    {
        $query = InventoryCostLayer::query()->where('product_id', $productId);

        return $sizeColorId !== null && $sizeColorId > 0
            ? $query->where('size_color_id', $sizeColorId)
            : $query->whereNull('size_color_id');
    }

    private function validateAndResolveSizeId(Product $product, ?int $sizeColorId, ?int $sizeId): ?int
    {
        if ($sizeColorId === null || $sizeColorId <= 0) {
            if ($this->stockService->productHasVariants($product->loadMissing('sizes.colorSizes'))) {
                throw ValidationException::withMessages(['size_color_id' => [__('messages.variant_required')]]);
            }

            return null;
        }

        $variant = SizeColor::query()->with('size')->lockForUpdate()->findOrFail($sizeColorId);
        if ((int) ($variant->size?->itemId ?? 0) !== (int) $product->id) {
            throw ValidationException::withMessages(['size_color_id' => [__('messages.validation_failed')]]);
        }

        return (int) $variant->sizeId;
    }

    private function physicalQuantity(Product $product, ?int $sizeColorId): float
    {
        if ($sizeColorId !== null && $sizeColorId > 0) {
            return (float) SizeColor::query()->whereKey($sizeColorId)->value('stock');
        }

        return (float) $product->fresh()?->stock;
    }

    private function wholeStockQuantity(float $quantity): int
    {
        if ($quantity <= 0 || abs($quantity - round($quantity)) > self::EPSILON) {
            throw ValidationException::withMessages([
                'quantity' => ['كمية المخزون يجب أن تكون عددًا صحيحًا أكبر من صفر.'],
            ]);
        }

        return (int) round($quantity);
    }

    private function markCoverageReview(Product $product, ?int $sizeColorId, ?int $sizeId, float $physical, float $costed): void
    {
        if (! Schema::hasTable('inventory_cost_reviews')) {
            return;
        }

        InventoryCostReview::query()->updateOrCreate(
            ['identity_key' => $this->identityKey((int) $product->id, $sizeColorId)],
            [
                'product_id' => $product->id,
                'size_id' => $sizeId,
                'size_color_id' => $sizeColorId,
                'physical_quantity' => $physical,
                'costed_quantity' => $costed,
                'missing_quantity' => max(0, $physical - $costed),
                'status' => 'pending',
                'reason' => 'missing_inventory_cost_coverage',
            ]
        );
    }

    private function collapseMovingAverageBalancesToFifoLayers(): void
    {
        InventoryCostBalance::query()->where('quantity', '>', 0)->orderBy('id')->chunkById(100, function ($balances) {
            foreach ($balances as $balance) {
                $balance = InventoryCostBalance::query()->lockForUpdate()->findOrFail($balance->id);
                $this->layerIdentityQuery((int) $balance->product_id, $balance->size_color_id)
                    ->where('remaining_quantity', '>', 0)
                    ->update(['remaining_quantity' => 0]);

                InventoryCostLayer::query()->create([
                    'product_id' => $balance->product_id,
                    'size_id' => $balance->size_id,
                    'size_color_id' => $balance->size_color_id,
                    'quantity' => $balance->quantity,
                    'remaining_quantity' => $balance->quantity,
                    'unit_cost' => $balance->moving_average_unit_cost,
                    'original_unit_cost' => $balance->moving_average_unit_cost,
                    'currency' => $balance->currency,
                    'source_type' => 'cost_method_conversion',
                    'source_id' => $balance->id,
                    'idempotency_key' => 'ma-to-fifo:'.$balance->id.':'.now()->format('YmdHis'),
                    'effective_at' => now(),
                ]);
            }
        });
    }
}
