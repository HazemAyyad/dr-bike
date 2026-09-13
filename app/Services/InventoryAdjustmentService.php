<?php

namespace App\Services;

use App\Models\InventoryAdjustment;
use App\Models\InventoryCostLayer;
use App\Models\InventoryCostRevaluationLine;
use App\Models\Product;
use App\Models\ProductStockMovement;
use App\Models\SizeColor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InventoryAdjustmentService
{
    private const EPSILON = 0.0001;

    public function __construct(
        private readonly InventoryCostingService $costing,
        private readonly ProductStockService $stock,
    ) {}

    public function adjustQuantity(
        Product $product,
        int $actualQuantity,
        string $reason,
        ?string $notes,
        ?float $positiveUnitCost,
        string $currency,
        ?int $sizeColorId,
        ?int $userId,
    ): InventoryAdjustment {
        $currency = $this->costing->normalizeCurrency($currency);
        if ($actualQuantity < 0) {
            throw ValidationException::withMessages(['actual_quantity' => ['الكمية الفعلية لا يمكن أن تكون سالبة.']]);
        }

        return DB::transaction(function () use ($product, $actualQuantity, $reason, $notes, $positiveUnitCost, $currency, $sizeColorId, $userId) {
            $product = Product::query()->lockForUpdate()->findOrFail($product->id);
            $sizeId = $this->validateIdentity($product, $sizeColorId);
            $before = $this->stock->resolveAvailableStock($product, $sizeColorId);
            $difference = $actualQuantity - $before;
            if ($difference === 0) {
                throw ValidationException::withMessages(['actual_quantity' => ['الكمية الفعلية مطابقة لكمية النظام؛ لا يوجد فرق لتسجيله.']]);
            }
            if ($difference > 0 && ($positiveUnitCost === null || $positiveUnitCost < 0)) {
                throw ValidationException::withMessages(['unit_cost' => ['تكلفة الوحدة مطلوبة عند زيادة المخزون.']]);
            }

            $method = $this->costing->currentMethod();
            $old = $this->costing->identitySummary($product, $sizeColorId, $sizeId, true, 0);
            $adjustment = InventoryAdjustment::query()->create([
                'reference' => $this->reference('ADJ'),
                'product_id' => $product->id,
                'size_id' => $sizeId,
                'size_color_id' => $sizeColorId,
                'adjustment_type' => InventoryAdjustment::TYPE_QUANTITY,
                'stock_before' => $before,
                'stock_after' => $actualQuantity,
                'quantity_difference' => $difference,
                'old_unit_cost' => $old['average_inventory_unit_cost'],
                'old_value' => $old['inventory_value'] ?? 0,
                'new_value' => 0,
                'value_difference' => 0,
                'currency' => $currency,
                'costing_method' => $method,
                'reason' => $reason,
                'notes' => $notes,
                'created_by' => $userId,
            ]);

            if ($difference > 0) {
                $this->costing->addOwnedStock(
                    product: $product,
                    quantity: $difference,
                    unitCost: (float) $positiveUnitCost,
                    currency: $currency,
                    sourceType: 'inventory_adjustment',
                    sourceId: $adjustment->id,
                    sizeColorId: $sizeColorId,
                    sizeId: $sizeId,
                    userId: $userId,
                    note: $notes,
                    movementType: ProductStockMovement::TYPE_STOCK_ADJUSTMENT_IN,
                    reason: $reason,
                    idempotencyKey: 'inventory-adjustment:'.$adjustment->id,
                );
            } else {
                $this->costing->consumeOwnedStock(
                    product: $product,
                    quantity: abs($difference),
                    movementType: ProductStockMovement::TYPE_STOCK_ADJUSTMENT_OUT,
                    referenceType: 'inventory_adjustment',
                    referenceId: $adjustment->id,
                    sizeColorId: $sizeColorId,
                    sizeId: $sizeId,
                    userId: $userId,
                    note: $notes,
                    reason: $reason,
                );
            }

            $freshProduct = $product->fresh(['sizes.colorSizes']);
            $new = $this->costing->identitySummary($freshProduct, $sizeColorId, $sizeId, true, 0);
            $movement = ProductStockMovement::query()
                ->where('reference_type', 'inventory_adjustment')
                ->where('reference_id', $adjustment->id)
                ->latest('id')
                ->first();

            $adjustment->update([
                'new_unit_cost' => $new['average_inventory_unit_cost'],
                'new_value' => $new['inventory_value'] ?? 0,
                'value_difference' => round((float) ($new['inventory_value'] ?? 0) - (float) ($old['inventory_value'] ?? 0), 6),
            ]);

            if ($movement) {
                $movement->update(['note' => $notes, 'reason' => $reason, 'costing_method' => $method]);
            }

            return $adjustment->fresh();
        });
    }

    public function revalue(
        Product $product,
        float $newUnitCost,
        string $reason,
        ?string $notes,
        string $currency,
        ?int $sizeColorId,
        ?int $userId,
    ): InventoryAdjustment {
        $currency = $this->costing->normalizeCurrency($currency);
        if ($newUnitCost < 0) {
            throw ValidationException::withMessages(['new_unit_cost' => ['تكلفة الوحدة الجديدة لا يمكن أن تكون سالبة.']]);
        }

        return DB::transaction(function () use ($product, $newUnitCost, $reason, $notes, $currency, $sizeColorId, $userId) {
            $product = Product::query()->lockForUpdate()->findOrFail($product->id);
            $this->costing->assertCurrencyCompatible($product, $currency);
            $sizeId = $this->validateIdentity($product, $sizeColorId);
            $method = $this->costing->currentMethod();
            $before = $this->costing->identitySummary($product, $sizeColorId, $sizeId, true, 0);
            $quantity = (float) $before['quantity_on_hand'];
            if ($quantity <= self::EPSILON || ! $before['cost_coverage_complete']) {
                throw ValidationException::withMessages([
                    'inventory' => ['يجب أن تكون كمية المخزون موجبة ومغطاة بالكامل قبل إعادة تقييم التكلفة.'],
                ]);
            }

            $newValue = round($quantity * $newUnitCost, 6);
            $adjustment = InventoryAdjustment::query()->create([
                'reference' => $this->reference('REV'),
                'product_id' => $product->id,
                'size_id' => $sizeId,
                'size_color_id' => $sizeColorId,
                'adjustment_type' => InventoryAdjustment::TYPE_COST_REVALUATION,
                'stock_before' => $quantity,
                'stock_after' => $quantity,
                'quantity_difference' => 0,
                'old_unit_cost' => $before['average_inventory_unit_cost'],
                'new_unit_cost' => $newUnitCost,
                'old_value' => $before['inventory_value'] ?? 0,
                'new_value' => $newValue,
                'value_difference' => round($newValue - (float) ($before['inventory_value'] ?? 0), 6),
                'currency' => $currency,
                'costing_method' => $method,
                'reason' => $reason,
                'notes' => $notes,
                'created_by' => $userId,
            ]);

            $balance = $this->costing->lockedBalance($product, $sizeColorId, $sizeId, $currency);
            if ($method === InventoryCostingService::METHOD_FIFO) {
                $layers = InventoryCostLayer::query()
                    ->where('product_id', $product->id)
                    ->when($sizeColorId,
                        fn ($query) => $query->where('size_color_id', $sizeColorId),
                        fn ($query) => $query->whereNull('size_color_id'))
                    ->where('remaining_quantity', '>', 0)
                    ->orderBy('effective_at')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                foreach ($layers as $layer) {
                    $layerQuantity = (float) $layer->remaining_quantity;
                    $oldUnitCost = (float) $layer->unit_cost;
                    InventoryCostRevaluationLine::query()->create([
                        'inventory_adjustment_id' => $adjustment->id,
                        'inventory_cost_layer_id' => $layer->id,
                        'quantity' => $layerQuantity,
                        'old_unit_cost' => $oldUnitCost,
                        'new_unit_cost' => $newUnitCost,
                        'old_value' => round($layerQuantity * $oldUnitCost, 6),
                        'new_value' => round($layerQuantity * $newUnitCost, 6),
                    ]);
                    $layer->update(['unit_cost' => $newUnitCost]);
                }
            } else {
                InventoryCostRevaluationLine::query()->create([
                    'inventory_adjustment_id' => $adjustment->id,
                    'inventory_cost_layer_id' => null,
                    'quantity' => $quantity,
                    'old_unit_cost' => $before['average_inventory_unit_cost'] ?? 0,
                    'new_unit_cost' => $newUnitCost,
                    'old_value' => $before['inventory_value'] ?? 0,
                    'new_value' => $newValue,
                ]);
            }

            $balance->update([
                'quantity' => $quantity,
                'inventory_value' => $newValue,
                'moving_average_unit_cost' => $newUnitCost,
                'currency' => $currency,
            ]);

            $this->stock->logMovement(
                productId: (int) $product->id,
                sizeId: $sizeId,
                sizeColorId: $sizeColorId,
                type: ProductStockMovement::TYPE_COST_REVALUATION,
                quantity: 0,
                stockBefore: (int) $quantity,
                stockAfter: (int) $quantity,
                referenceType: 'inventory_adjustment',
                referenceId: (int) $adjustment->id,
                note: $notes,
                userId: $userId,
                unitCost: $newUnitCost,
                totalCost: (float) $adjustment->value_difference,
                costingMethod: $method,
                reason: $reason,
            );

            return $adjustment->fresh();
        });
    }

    public function initializeMissingCost(
        Product $product,
        float $unitCost,
        string $reason,
        ?string $notes,
        string $currency,
        ?int $sizeColorId,
        ?int $userId,
    ): InventoryAdjustment {
        $currency = $this->costing->normalizeCurrency($currency);
        if ($unitCost <= 0) {
            throw ValidationException::withMessages(['unit_cost' => ['تكلفة الوحدة يجب أن تكون أكبر من صفر.']]);
        }

        return DB::transaction(function () use ($product, $unitCost, $reason, $notes, $currency, $sizeColorId, $userId) {
            $product = Product::query()->lockForUpdate()->findOrFail($product->id);
            $this->costing->assertCurrencyCompatible($product, $currency);
            $sizeId = $this->validateIdentity($product, $sizeColorId);
            $method = $this->costing->currentMethod();
            $before = $this->costing->identitySummary($product, $sizeColorId, $sizeId, true, 0);
            $quantity = (float) $before['quantity_on_hand'];
            $missingQuantity = round($quantity - (float) $before['costed_quantity'], 4);
            if ($quantity <= self::EPSILON || $missingQuantity <= self::EPSILON) {
                throw ValidationException::withMessages([
                    'inventory' => ['لا توجد كمية مخزون موجبة ناقصة التغطية لإضافة تكلفة لها.'],
                ]);
            }

            $adjustment = InventoryAdjustment::query()->create([
                'reference' => $this->reference('CST'),
                'product_id' => $product->id,
                'size_id' => $sizeId,
                'size_color_id' => $sizeColorId,
                'adjustment_type' => InventoryAdjustment::TYPE_COST_INITIALIZATION,
                'stock_before' => $quantity,
                'stock_after' => $quantity,
                'quantity_difference' => 0,
                'old_unit_cost' => $before['average_inventory_unit_cost'],
                'old_value' => $before['inventory_value'] ?? 0,
                'new_value' => 0,
                'value_difference' => 0,
                'currency' => $currency,
                'costing_method' => $method,
                'reason' => $reason,
                'notes' => $notes,
                'created_by' => $userId,
            ]);

            $layer = $this->costing->coverMissingCost(
                product: $product,
                unitCost: $unitCost,
                currency: $currency,
                sourceType: 'inventory_cost_initialization',
                sourceId: $adjustment->id,
                sizeColorId: $sizeColorId,
                sizeId: $sizeId,
                userId: $userId,
                idempotencyKey: 'inventory-cost-initialization:'.$adjustment->id,
            );
            $after = $this->costing->identitySummary($product->fresh(), $sizeColorId, $sizeId, true, 0);

            InventoryCostRevaluationLine::query()->create([
                'inventory_adjustment_id' => $adjustment->id,
                'inventory_cost_layer_id' => $layer->id,
                'quantity' => $missingQuantity,
                'old_unit_cost' => 0,
                'new_unit_cost' => $unitCost,
                'old_value' => 0,
                'new_value' => round($missingQuantity * $unitCost, 6),
            ]);

            $adjustment->update([
                'new_unit_cost' => $after['average_inventory_unit_cost'],
                'new_value' => $after['inventory_value'] ?? 0,
                'value_difference' => round((float) ($after['inventory_value'] ?? 0) - (float) ($before['inventory_value'] ?? 0), 6),
            ]);

            $this->stock->logMovement(
                productId: (int) $product->id,
                sizeId: $sizeId,
                sizeColorId: $sizeColorId,
                type: ProductStockMovement::TYPE_COST_INITIALIZATION,
                quantity: 0,
                stockBefore: (int) $quantity,
                stockAfter: (int) $quantity,
                referenceType: 'inventory_adjustment',
                referenceId: (int) $adjustment->id,
                note: $notes,
                userId: $userId,
                unitCost: $unitCost,
                totalCost: (float) $adjustment->value_difference,
                costingMethod: $method,
                reason: $reason,
            );

            return $adjustment->fresh();
        });
    }

    private function validateIdentity(Product $product, ?int $sizeColorId): ?int
    {
        if ($sizeColorId === null || $sizeColorId <= 0) {
            if ($this->stock->productHasVariants($product->loadMissing('sizes.colorSizes'))) {
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

    private function reference(string $prefix): string
    {
        return $prefix.'-'.Str::upper((string) Str::ulid());
    }
}
