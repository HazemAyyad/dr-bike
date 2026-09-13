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
            $oldQuantity = (float) $balance->quantity;
            $oldValue = (float) $balance->inventory_value;
            $addedValue = round($quantity * $unitCost, 6);
            $newQuantity = round($oldQuantity + $quantity, 4);
            $newValue = round($oldValue + $addedValue, 6);
            $newAverage = $newQuantity > self::EPSILON ? $newValue / $newQuantity : 0.0;

            $layer = InventoryCostLayer::create([
                'product_id' => $lockedProduct->id,
                'size_id' => $sizeId,
                'size_color_id' => $sizeColorId,
                'quantity' => $quantity,
                'remaining_quantity' => $quantity,
                'unit_cost' => $unitCost,
                'original_unit_cost' => $unitCost,
                'currency' => $currency,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'idempotency_key' => $idempotencyKey,
                'effective_at' => now(),
            ]);

            $balance->update([
                'quantity' => $newQuantity,
                'inventory_value' => $newValue,
                'moving_average_unit_cost' => $newAverage,
                'currency' => $currency,
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
    ): array {
        if ($quantity <= 0) {
            return ['method' => $this->currentMethod(), 'total_cost' => 0.0, 'unit_cost' => 0.0, 'allocations' => []];
        }

        $this->wholeStockQuantity($quantity);

        return DB::transaction(function () use ($product, $quantity, $referenceType, $referenceId, $sizeColorId, $sizeId) {
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
            if ($available + self::EPSILON < $quantity || (float) $balance->quantity + self::EPSILON < $quantity) {
                $this->markCoverageReview($lockedProduct, $sizeColorId, $sizeId, $this->physicalQuantity($lockedProduct, $sizeColorId), $available);
                throw ValidationException::withMessages([
                    'inventory' => ['كمية المخزون غير مغطاة بالكامل بتكلفة محاسبية. يجب معالجة مراجعة تكلفة المخزون أولاً.'],
                ]);
            }

            $movingAverage = $method === self::METHOD_MOVING_AVERAGE
                ? (float) $balance->moving_average_unit_cost
                : null;
            $remaining = $quantity;
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

            $newQuantity = max(0, round((float) $balance->quantity - $quantity, 4));
            $newValue = max(0, round((float) $balance->inventory_value - $totalCost, 6));
            $balance->update([
                'quantity' => $newQuantity,
                'inventory_value' => $newQuantity > self::EPSILON ? $newValue : 0,
                // A sale never changes the perpetual moving-average unit cost.
                'moving_average_unit_cost' => $method === self::METHOD_MOVING_AVERAGE
                    ? ($newQuantity > self::EPSILON ? (float) $balance->moving_average_unit_cost : 0)
                    : ($newQuantity > self::EPSILON ? $newValue / $newQuantity : 0),
            ]);

            return [
                'method' => $method,
                'total_cost' => round($totalCost, 6),
                'unit_cost' => $quantity > 0 ? round($totalCost / $quantity, 6) : 0.0,
                'allocations' => $allocations,
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
                'costing_method' => $method,
                'currency' => $currency,
                'inventory_value' => $includeCost ? round((float) $value, 6) : null,
                'average_inventory_unit_cost' => $includeCost && $quantity > self::EPSILON ? round((float) $value / $quantity, 6) : null,
                'next_fifo_unit_cost' => $includeCost && $method === self::METHOD_FIFO ? ($nextLayer['next_fifo_unit_cost'] ?? null) : null,
                'cost_coverage_complete' => $currencyConsistent && abs($quantity - $costedQuantity) <= self::EPSILON,
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

        $value = $method === self::METHOD_MOVING_AVERAGE && $balance
            ? (float) $balance->inventory_value
            : $fifoValue;
        $average = $physical > self::EPSILON ? $value / $physical : 0.0;
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
            'costing_method' => $method,
            'currency' => $currency,
            'inventory_value' => $includeCost ? round($value, 6) : null,
            'average_inventory_unit_cost' => $includeCost ? round($average, 6) : null,
            'next_fifo_unit_cost' => $includeCost && $method === self::METHOD_FIFO && $next ? (float) $next->unit_cost : null,
            'next_fifo_effective_at' => $includeCost && $method === self::METHOD_FIFO && $next ? optional($next->effective_at)->toIso8601String() : null,
            'cost_coverage_complete' => abs($physical - $costedQuantity) <= self::EPSILON,
            'cost_layers' => $layers,
        ];
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
