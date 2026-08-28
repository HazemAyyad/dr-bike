<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\InventoryCostAllocation;
use App\Models\InventoryCostLayer;
use App\Models\Product;
use App\Models\ProductStockMovement;
use Illuminate\Support\Facades\DB;

class InventoryCostingService
{
    public const METHOD_FIFO = 'fifo';

    public const METHOD_MOVING_AVERAGE = 'moving_average';

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

        AppSetting::query()->updateOrCreate(
            ['key' => AppSetting::KEY_INVENTORY_COSTING_METHOD],
            ['value' => $method]
        );

        AppSetting::query()->updateOrCreate(
            ['key' => AppSetting::KEY_INVENTORY_COSTING_METHOD_EFFECTIVE_FROM],
            ['value' => now()->toDateTimeString()]
        );
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
    ): InventoryCostLayer {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Quantity must be positive.');
        }

        return DB::transaction(function () use ($product, $quantity, $unitCost, $currency, $sourceType, $sourceId, $sizeColorId, $sizeId, $userId, $note, $movementType) {
            $layer = InventoryCostLayer::create([
                'product_id' => $product->id,
                'size_id' => $sizeId,
                'size_color_id' => $sizeColorId,
                'quantity' => $quantity,
                'remaining_quantity' => $quantity,
                'unit_cost' => $unitCost,
                'currency' => $currency,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'effective_at' => now(),
            ]);

            $this->stockService->adjustStock(
                product: $product,
                quantityDelta: (int) round($quantity),
                type: $movementType,
                sizeColorId: $sizeColorId,
                referenceType: $sourceType,
                referenceId: $sourceId,
                note: $note,
                userId: $userId,
                unitCost: $unitCost,
                totalCost: $quantity * $unitCost,
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
    ): array {
        if ($quantity <= 0) {
            return ['method' => $this->currentMethod(), 'total_cost' => 0.0, 'unit_cost' => 0.0, 'allocations' => []];
        }

        return DB::transaction(function () use ($product, $quantity, $referenceType, $referenceId) {
            $method = $this->currentMethod();
            $unitCost = $method === self::METHOD_MOVING_AVERAGE
                ? $this->movingAverageUnitCost((int) $product->id)
                : null;

            $remaining = $quantity;
            $totalCost = 0.0;
            $allocations = [];

            $layers = InventoryCostLayer::query()
                ->where('product_id', $product->id)
                ->where('remaining_quantity', '>', 0)
                ->orderBy('effective_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($layers as $layer) {
                if ($remaining <= 0.000001) {
                    break;
                }

                $take = min((float) $layer->remaining_quantity, $remaining);
                $cost = $unitCost ?? (float) $layer->unit_cost;
                $lineTotal = $take * $cost;

                $layer->update([
                    'remaining_quantity' => max(0, (float) $layer->remaining_quantity - $take),
                ]);

                $allocations[] = InventoryCostAllocation::create([
                    'inventory_cost_layer_id' => $layer->id,
                    'product_id' => $product->id,
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

            if ($remaining > 0.000001) {
                throw new \RuntimeException(__('messages.cant_sale'));
            }

            return [
                'method' => $method,
                'total_cost' => $totalCost,
                'unit_cost' => $quantity > 0 ? $totalCost / $quantity : 0.0,
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
    ): array {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Quantity must be positive.');
        }

        return DB::transaction(function () use ($product, $quantity, $movementType, $referenceType, $referenceId, $sizeColorId, $sizeId, $userId, $note) {
            $cost = $this->consumeCost($product, $quantity, $referenceType, $referenceId);

            $this->stockService->adjustStock(
                product: $product,
                quantityDelta: -1 * (int) round($quantity),
                type: $movementType,
                sizeColorId: $sizeColorId,
                referenceType: $referenceType,
                referenceId: $referenceId,
                note: $note,
                userId: $userId,
                unitCost: $cost['unit_cost'],
                totalCost: $cost['total_cost'],
            );

            return $cost;
        });
    }

    /** Consume a quantity from one explicitly selected cost layer. */
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
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Quantity must be positive.');
        }

        return DB::transaction(function () use ($product, $selectedLayer, $quantity, $movementType, $referenceType, $referenceId, $userId, $note) {
            $layer = InventoryCostLayer::query()->lockForUpdate()->findOrFail($selectedLayer->id);
            if ((int) $layer->product_id !== (int) $product->id || (float) $layer->remaining_quantity < $quantity) {
                throw new \RuntimeException('الكمية المطلوبة غير متاحة ضمن طبقة التكلفة المختارة.');
            }

            $unitCost = (float) $layer->unit_cost;
            $totalCost = $quantity * $unitCost;
            $layer->decrement('remaining_quantity', $quantity);

            $allocation = InventoryCostAllocation::create([
                'inventory_cost_layer_id' => $layer->id,
                'product_id' => $product->id,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'total_cost' => $totalCost,
                'method' => 'manual_layer',
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
            ]);

            $this->stockService->adjustStock(
                product: $product,
                quantityDelta: -1 * (int) round($quantity),
                type: $movementType,
                sizeColorId: $layer->size_color_id,
                referenceType: $referenceType,
                referenceId: $referenceId,
                note: $note,
                userId: $userId,
                unitCost: $unitCost,
                totalCost: $totalCost,
            );

            return [
                'method' => 'manual_layer',
                'total_cost' => $totalCost,
                'unit_cost' => $unitCost,
                'allocations' => [$allocation],
            ];
        });
    }

    private function movingAverageUnitCost(int $productId): float
    {
        $layers = InventoryCostLayer::query()
            ->where('product_id', $productId)
            ->where('remaining_quantity', '>', 0)
            ->lockForUpdate()
            ->get();

        $quantity = (float) $layers->sum('remaining_quantity');
        if ($quantity <= 0.000001) {
            return 0.0;
        }

        $value = $layers->sum(fn (InventoryCostLayer $layer) => (float) $layer->remaining_quantity * (float) $layer->unit_cost);

        return $value / $quantity;
    }
}
