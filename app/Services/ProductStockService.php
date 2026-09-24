<?php

namespace App\Services;

use App\Models\Closeout;
use App\Models\InventoryCostAllocation;
use App\Models\InventoryCostLayer;
use App\Models\Product;
use App\Models\ProductStockMovement;
use App\Models\Size;
use App\Models\SizeColor;
use App\Support\ApiImageUrl;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class ProductStockService
{
    public function productHasVariants(Product $product): bool
    {
        if ($product->relationLoaded('sizes')) {
            return $product->sizes->isNotEmpty()
                && $product->sizes->contains(fn (Size $size) => $size->relationLoaded('colorSizes')
                    ? $size->colorSizes->isNotEmpty()
                    : $size->colorSizes()->exists());
        }

        return Size::query()
            ->where('itemId', $product->id)
            ->whereHas('colorSizes')
            ->exists();
    }

    public function sumVariantStock(Product $product): int
    {
        if (! $product->relationLoaded('sizes')) {
            $product->load('sizes.colorSizes');
        }

        $sum = 0;
        foreach ($product->sizes as $size) {
            foreach ($size->colorSizes as $color) {
                $sum += (int) $color->stock;
            }
        }

        return $sum;
    }

    public function resolveDisplayStock(Product $product): int
    {
        if ($this->productHasVariants($product)) {
            return $this->sumVariantStock($product);
        }

        return (int) $product->stock;
    }

    public function syncProductTotalStock(Product $product): void
    {
        if (! $this->productHasVariants($product)) {
            return;
        }

        $total = $this->sumVariantStock($product);
        Product::query()->where('id', $product->id)->update(['stock' => $total]);
        $product->stock = $total;
    }

    public function resolveAvailableStock(Product $product, ?int $sizeColorId = null): int
    {
        if ($sizeColorId !== null && $sizeColorId > 0) {
            $variant = SizeColor::query()
                ->whereKey($sizeColorId)
                ->whereHas('size', fn ($query) => $query->where('itemId', $product->id))
                ->first();
            if (! $variant instanceof SizeColor) {
                return 0;
            }

            return (int) $variant->stock;
        }

        if ($this->productHasVariants($product)) {
            return $this->sumVariantStock($product);
        }

        return (int) $product->stock;
    }

    /**
     * @return array{ok: bool, message?: string, size_id?: int|null, size_color_id?: int|null, legacy_aggregate_stock?: bool}
     */
    public function validateSaleStock(
        Product $product,
        int $quantity,
        ?int $sizeColorId = null,
        bool $allowNegative = false
    ): array {
        if ($quantity < 1) {
            return ['ok' => false, 'message' => __('messages.cant_sale')];
        }

        if ($sizeColorId !== null && $sizeColorId > 0) {
            return $this->validateExplicitVariantSale($product, $quantity, $sizeColorId, $allowNegative);
        }

        $hasVariants = $this->productHasVariants($product);

        if (! $hasVariants) {
            if ($allowNegative) {
                return ['ok' => true, 'size_id' => null];
            }

            if ((int) $product->stock < $quantity || (int) $product->stock <= 0) {
                return ['ok' => false, 'message' => __('messages.cant_sale')];
            }

            return ['ok' => true, 'size_id' => null];
        }

        // Legacy clients (old app builds / suspended invoices) may omit size/color.
        $autoVariant = $this->resolveSingleAvailableVariant($product, $quantity);
        if ($autoVariant instanceof SizeColor) {
            return [
                'ok' => true,
                'size_color_id' => (int) $autoVariant->id,
                'size_id' => (int) $autoVariant->sizeId,
            ];
        }

        $product->loadMissing('sizes.colorSizes');
        $aggregateStock = max((int) $product->stock, $this->sumVariantStock($product));
        if ($aggregateStock >= $quantity && $aggregateStock > 0) {
            return [
                'ok' => true,
                'size_id' => null,
                'legacy_aggregate_stock' => true,
            ];
        }

        return ['ok' => false, 'message' => __('messages.variant_required')];
    }

    /**
     * @return array{ok: bool, message?: string, size_id?: int|null, size_color_id?: int}
     */
    private function validateExplicitVariantSale(
        Product $product,
        int $quantity,
        int $sizeColorId,
        bool $allowNegative = false
    ): array {
        $variant = SizeColor::query()
            ->with('size')
            ->find($sizeColorId);

        if (! $variant instanceof SizeColor) {
            return ['ok' => false, 'message' => __('messages.cant_sale')];
        }

        $size = $variant->size;
        if (! $size instanceof Size || (int) $size->itemId !== (int) $product->id) {
            return ['ok' => false, 'message' => __('messages.cant_sale')];
        }

        if (! $allowNegative && (int) $variant->stock < $quantity) {
            return ['ok' => false, 'message' => __('messages.cant_sale')];
        }

        return [
            'ok' => true,
            'size_id' => (int) $variant->sizeId,
            'size_color_id' => (int) $variant->id,
        ];
    }

    private function resolveSingleAvailableVariant(Product $product, int $quantity): ?SizeColor
    {
        $product->loadMissing('sizes.colorSizes');
        $eligible = [];

        foreach ($product->sizes as $size) {
            foreach ($size->colorSizes as $variant) {
                if ((int) $variant->stock >= $quantity) {
                    $eligible[] = $variant;
                }
            }
        }

        if (count($eligible) === 1) {
            return $eligible[0];
        }

        return null;
    }

    public function deductForSale(
        Product $product,
        int $quantity,
        ?int $sizeColorId = null,
        ?int $sizeId = null,
        ?string $referenceType = 'instant_sale',
        ?int $referenceId = null,
        ?string $note = null,
        ?int $userId = null,
        bool $allowNegative = false,
    ): array {
        return DB::transaction(function () use ($product, $quantity, $sizeColorId, $sizeId, $referenceType, $referenceId, $note, $userId, $allowNegative) {
            if ($quantity <= 0) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'quantity' => [__('messages.validation_failed')],
                ]);
            }

            $lockedProduct = Product::lockForUpdate()->findOrFail($product->id);
            $result = [
                'product_id' => (int) $lockedProduct->id,
                'size_id' => $sizeId,
                'size_color_id' => $sizeColorId,
                'stock_before' => 0,
                'stock_after' => 0,
            ];

            if ($sizeColorId !== null && $sizeColorId > 0) {
                $variant = SizeColor::query()
                    ->whereKey($sizeColorId)
                    ->whereHas('size', fn ($query) => $query->where('itemId', $lockedProduct->id))
                    ->lockForUpdate()
                    ->firstOrFail();
                $before = (int) $variant->stock;
                if (! $allowNegative && $before < $quantity) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'quantity' => [__('messages.cant_sale')],
                    ]);
                }
                $after = $before - $quantity;
                $cost = $this->tryConsumeCostSnapshot($lockedProduct, $quantity, $referenceType, $referenceId, $sizeColorId, $sizeId ?? (int) $variant->sizeId, $allowNegative);
                $variant->update(['stock' => $after]);
                $result = [
                    'product_id' => (int) $lockedProduct->id,
                    'size_id' => $sizeId ?? (int) $variant->sizeId,
                    'size_color_id' => $sizeColorId,
                    'stock_before' => $before,
                    'stock_after' => $after,
                ];

                $this->logMovement(
                    productId: (int) $lockedProduct->id,
                    sizeId: $sizeId ?? (int) $variant->sizeId,
                    sizeColorId: $sizeColorId,
                    type: $referenceType === 'maintenance' ? ProductStockMovement::TYPE_MAINTENANCE : ProductStockMovement::TYPE_SALE,
                    quantity: -$quantity,
                    stockBefore: $before,
                    stockAfter: $after,
                    referenceType: $referenceType,
                    referenceId: $referenceId,
                    note: $note,
                    userId: $userId,
                    unitCost: ($cost['cost_complete'] ?? true) ? ($cost['unit_cost'] ?? null) : null,
                    totalCost: ($cost['cost_complete'] ?? true) ? ($cost['total_cost'] ?? null) : null,
                    costingMethod: $cost['method'] ?? null,
                );
                $this->persistOutboundCostSnapshot($lockedProduct, $quantity, $referenceType, $referenceId, $cost, $sizeColorId, $sizeId ?? (int) $variant->sizeId);

                $this->syncProductTotalStock($lockedProduct->fresh(['sizes.colorSizes']));
            } else {
                $before = (int) $lockedProduct->stock;
                if (! $allowNegative && $before < $quantity) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'quantity' => [__('messages.cant_sale')],
                    ]);
                }
                $after = $before - $quantity;
                $cost = $this->tryConsumeCostSnapshot($lockedProduct, $quantity, $referenceType, $referenceId, null, null, $allowNegative);
                $lockedProduct->update(['stock' => $after]);
                $result = [
                    'product_id' => (int) $lockedProduct->id,
                    'size_id' => null,
                    'size_color_id' => null,
                    'stock_before' => $before,
                    'stock_after' => $after,
                ];

                $this->logMovement(
                    productId: (int) $lockedProduct->id,
                    sizeId: null,
                    sizeColorId: null,
                    type: $referenceType === 'maintenance' ? ProductStockMovement::TYPE_MAINTENANCE : ProductStockMovement::TYPE_SALE,
                    quantity: -$quantity,
                    stockBefore: $before,
                    stockAfter: $after,
                    referenceType: $referenceType,
                    referenceId: $referenceId,
                    note: $note,
                    userId: $userId,
                    unitCost: ($cost['cost_complete'] ?? true) ? ($cost['unit_cost'] ?? null) : null,
                    totalCost: ($cost['cost_complete'] ?? true) ? ($cost['total_cost'] ?? null) : null,
                    costingMethod: $cost['method'] ?? null,
                );
                $this->persistOutboundCostSnapshot($lockedProduct, $quantity, $referenceType, $referenceId, $cost, null, null);
            }

            $this->refreshCloseoutStatus((int) $lockedProduct->id);

            return $result;
        });
    }

    public function restoreForSale(
        Product $product,
        int $quantity,
        ?int $sizeColorId = null,
        ?int $sizeId = null,
        ?string $referenceType = 'instant_sale',
        ?int $referenceId = null,
        ?string $note = null,
        ?int $userId = null,
        ?float $unitCost = null,
        ?float $totalCost = null,
        ?string $costSourceType = null,
        ?int $costSourceId = null,
    ): ?array {
        if ($quantity <= 0) {
            return null;
        }

        return DB::transaction(function () use ($product, $quantity, $sizeColorId, $sizeId, $referenceType, $referenceId, $note, $userId, $unitCost, $totalCost, $costSourceType, $costSourceId) {
            $lockedProduct = Product::withTrashed()->lockForUpdate()->find($product->id);
            if (! $lockedProduct instanceof Product) {
                return null;
            }

            $pendingReversal = app(InventoryCostingService::class)->reversePendingNegativeCost(
                $lockedProduct,
                $quantity,
                $referenceType ?? 'instant_sale',
                $referenceId,
                $sizeColorId,
                $sizeId,
            );
            $pendingQuantity = (int) round((float) ($pendingReversal['quantity'] ?? 0));
            if ($pendingQuantity > 0) {
                $this->adjustStock(
                    product: $lockedProduct,
                    quantityDelta: $pendingQuantity,
                    type: $referenceType === 'sales_return'
                        ? ProductStockMovement::TYPE_SALES_RETURN
                        : ProductStockMovement::TYPE_SALE_CANCEL,
                    sizeColorId: $sizeColorId,
                    referenceType: $referenceType,
                    referenceId: $referenceId,
                    note: $note,
                    userId: $userId,
                    unitCost: null,
                    totalCost: null,
                    costingMethod: $pendingReversal['method'] ?? null,
                    reason: 'عكس بيع سالب قبل اكتمال تكلفة المخزون',
                );
                $quantity -= $pendingQuantity;
                if ($quantity <= 0) {
                    $this->refreshCloseoutStatus((int) $lockedProduct->id, reopen: true);

                    return [
                        'unit_cost' => null,
                        'total_cost' => null,
                        'cost_complete' => false,
                    ];
                }
                $lockedProduct = $lockedProduct->fresh(['sizes.colorSizes']);
            }

            $unitCost ??= $this->resolveRestorationUnitCost(
                (int) $lockedProduct->id,
                $sizeColorId,
                $referenceType,
                $referenceId,
            );
            $movementType = $referenceType === 'sales_return'
                ? ProductStockMovement::TYPE_SALES_RETURN
                : ProductStockMovement::TYPE_SALE_CANCEL;

            if ($unitCost !== null) {
                app(InventoryCostingService::class)->addOwnedStock(
                    product: $lockedProduct,
                    quantity: $quantity,
                    unitCost: $unitCost,
                    currency: 'شيكل',
                    sourceType: $costSourceType ?? $referenceType ?? 'sale_return',
                    sourceId: $costSourceId ?? $referenceId,
                    sizeColorId: $sizeColorId,
                    sizeId: $sizeId,
                    userId: $userId,
                    note: $note,
                    movementType: $movementType,
                    reason: $note,
                    movementReferenceType: $referenceType,
                    movementReferenceId: $referenceId,
                );

                $this->refreshCloseoutStatus((int) $lockedProduct->id, reopen: true);

                return [
                    'unit_cost' => round((float) $unitCost, 6),
                    'total_cost' => round((float) ($totalCost ?? ($unitCost * $quantity)), 4),
                    'cost_complete' => true,
                ];
            }

            throw \Illuminate\Validation\ValidationException::withMessages([
                'inventory' => ['تعذر تحديد تكلفة المخزون المرتجع من حركة البيع الأصلية. يلزم إجراء مراجعة إدارية قبل الاستعادة.'],
            ]);
        });
    }

    public function adjustStock(
        Product $product,
        int $quantityDelta,
        string $type,
        ?int $sizeColorId = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $note = null,
        ?int $userId = null,
        ?float $unitCost = null,
        ?float $totalCost = null,
        ?string $costingMethod = null,
        ?string $reason = null,
    ): void {
        if ($quantityDelta === 0) {
            return;
        }

        DB::transaction(function () use ($product, $quantityDelta, $type, $sizeColorId, $referenceType, $referenceId, $note, $userId, $unitCost, $totalCost, $costingMethod, $reason) {
            $productQuery = $product->trashed()
                ? Product::withTrashed()
                : Product::query();
            $lockedProduct = $productQuery->lockForUpdate()->findOrFail($product->id);

            if ($sizeColorId !== null && $sizeColorId > 0) {
                $variant = SizeColor::query()
                    ->whereKey($sizeColorId)
                    ->whereHas('size', fn ($query) => $query->where('itemId', $lockedProduct->id))
                    ->lockForUpdate()
                    ->firstOrFail();
                $before = (int) $variant->stock;
                $after = $before + $quantityDelta;
                if ($after < 0) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'actual_quantity' => [__('messages.cant_sale')],
                    ]);
                }
                $variant->update(['stock' => $after]);

                $this->logMovement(
                    productId: (int) $lockedProduct->id,
                    sizeId: (int) $variant->sizeId,
                    sizeColorId: $sizeColorId,
                    type: $type,
                    quantity: $quantityDelta,
                    stockBefore: $before,
                    stockAfter: $after,
                    referenceType: $referenceType,
                    referenceId: $referenceId,
                    note: $note,
                    userId: $userId,
                    unitCost: $unitCost,
                    totalCost: $totalCost,
                    costingMethod: $costingMethod,
                    reason: $reason,
                );

                $this->syncProductTotalStock($lockedProduct->fresh(['sizes.colorSizes']));
            } else {
                $before = (int) $lockedProduct->stock;
                $after = $before + $quantityDelta;
                if ($after < 0) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'actual_quantity' => [__('messages.cant_sale')],
                    ]);
                }
                $lockedProduct->update(['stock' => $after]);

                $this->logMovement(
                    productId: (int) $lockedProduct->id,
                    sizeId: null,
                    sizeColorId: null,
                    type: $type,
                    quantity: $quantityDelta,
                    stockBefore: $before,
                    stockAfter: $after,
                    referenceType: $referenceType,
                    referenceId: $referenceId,
                    note: $note,
                    userId: $userId,
                    unitCost: $unitCost,
                    totalCost: $totalCost,
                    costingMethod: $costingMethod,
                    reason: $reason,
                );
            }

            $this->refreshCloseoutStatus((int) $lockedProduct->id, reopen: $quantityDelta > 0);
        });
    }

    /**
     * After saving variant rows, align product total with variant sum.
     */
    public function afterVariantsSaved(Product $product): void
    {
        if (! $this->productHasVariants($product->fresh(['sizes.colorSizes']))) {
            return;
        }

        $this->syncProductTotalStock($product->fresh(['sizes.colorSizes']));
    }

    /**
     * @return array{has_variants: bool, stock: int, sizes: array<int, array<string, mixed>>}
     */
    public function formatProductForSaleApi(Product $product): array
    {
        if (! $product->relationLoaded('sizes')) {
            $product->load(['sizes.colorSizes']);
        }

        $hasVariants = $this->productHasVariants($product);
        $sizes = [];

        if ($hasVariants) {
            foreach ($product->sizes as $size) {
                $colorRows = [];
                foreach ($size->colorSizes as $color) {
                    $variantPrice = (float) ($color->normailPrice ?? 0);
                    $colorRows[] = [
                        'id' => (int) $color->id,
                        'size_id' => (int) $color->sizeId,
                        'colorAr' => (string) $color->colorAr,
                        'colorEn' => $color->colorEn,
                        'colorAbbr' => $color->colorAbbr,
                        'stock' => (int) $color->stock,
                        'normailPrice' => $variantPrice,
                        'wholesalePrice' => (float) ($color->wholesalePrice ?? 0),
                        'discount' => (float) ($color->discount ?? 0),
                        'image_url' => ApiImageUrl::normalize($color->image_url ?? null),
                    ];
                }

                if ($colorRows !== []) {
                    $sizes[] = [
                        'id' => (int) $size->id,
                        'size' => (string) $size->size,
                        'color_sizes' => $colorRows,
                    ];
                }
            }
        }

        return [
            'has_variants' => $hasVariants && $sizes !== [],
            'stock' => $hasVariants ? $this->sumVariantStock($product) : (int) $product->stock,
            'sizes' => $sizes,
        ];
    }

    /**
     * @return array{total_in: int, total_out: int, current_stock: int, purchased: int, sold: int, sales_returned: int, purchase_returned: int}
     */
    public function movementSummary(int $productId, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $query = ProductStockMovement::query()->where('product_id', $productId);

        if ($dateFrom) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }
        if ($dateTo) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        $rows = $query->get(['quantity', 'type']);
        $totalIn = 0;
        $totalOut = 0;
        $purchased = 0;
        $sold = 0;
        $salesReturned = 0;
        $purchaseReturned = 0;
        foreach ($rows as $row) {
            $q = (int) $row->quantity;
            if ($q > 0) {
                $totalIn += $q;
            } else {
                $totalOut += abs($q);
            }
            if (in_array($row->type, [ProductStockMovement::TYPE_PURCHASE, ProductStockMovement::TYPE_BILL_QUANTITY], true)) {
                $purchased += max(0, $q);
            } elseif ($row->type === ProductStockMovement::TYPE_SALE) {
                $sold += abs(min(0, $q));
            } elseif ($row->type === ProductStockMovement::TYPE_SALES_RETURN) {
                $salesReturned += max(0, $q);
            } elseif ($row->type === ProductStockMovement::TYPE_SALES_RETURN_CANCEL) {
                $salesReturned -= abs(min(0, $q));
            } elseif ($row->type === ProductStockMovement::TYPE_PURCHASE_RETURN) {
                $purchaseReturned += abs(min(0, $q));
            } elseif ($row->type === ProductStockMovement::TYPE_PURCHASE_RETURN_CANCEL) {
                $purchaseReturned -= max(0, $q);
            }
        }

        $product = Product::query()->find($productId);

        return [
            'total_in' => $totalIn,
            'total_out' => $totalOut,
            'current_stock' => $product ? $this->resolveDisplayStock($product->load('sizes.colorSizes')) : 0,
            'purchased' => $purchased,
            'sold' => $sold,
            'sales_returned' => max(0, $salesReturned),
            'purchase_returned' => max(0, $purchaseReturned),
        ];
    }

    public function logMovement(
        int $productId,
        ?int $sizeId,
        ?int $sizeColorId,
        string $type,
        int $quantity,
        int $stockBefore,
        int $stockAfter,
        ?string $referenceType,
        ?int $referenceId,
        ?string $note,
        ?int $userId,
        ?float $unitCost = null,
        ?float $totalCost = null,
        ?string $costingMethod = null,
        ?string $reason = null,
        ?int $reversalOfId = null,
    ): void {
        if (! Schema::hasTable('product_stock_movements')) {
            return;
        }

        $payload = [
            'product_id' => $productId,
            'size_id' => $sizeId,
            'size_color_id' => $sizeColorId,
            'type' => $type,
            'quantity' => $quantity,
            'stock_before' => $stockBefore,
            'stock_after' => $stockAfter,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'note' => $note,
            'created_by' => $userId,
        ];

        if (Schema::hasColumn('product_stock_movements', 'unit_cost')) {
            $payload['unit_cost'] = $unitCost;
        }
        if (Schema::hasColumn('product_stock_movements', 'total_cost')) {
            $payload['total_cost'] = $totalCost;
        }
        if (Schema::hasColumn('product_stock_movements', 'costing_method')) {
            $payload['costing_method'] = $costingMethod;
        }
        if (Schema::hasColumn('product_stock_movements', 'reason')) {
            $payload['reason'] = $reason;
        }
        if (Schema::hasColumn('product_stock_movements', 'reversal_of_id')) {
            $payload['reversal_of_id'] = $reversalOfId;
        }

        ProductStockMovement::create($payload);
    }

    /**
     * @return array{method: string, total_cost: float, unit_cost: float}|null
     */
    private function tryConsumeCostSnapshot(
        Product $product,
        int $quantity,
        ?string $referenceType,
        ?int $referenceId,
        ?int $sizeColorId,
        ?int $sizeId,
        bool $allowNegative = false,
    ): ?array {
        if ($quantity <= 0 || ! in_array($referenceType, ['instant_sale', 'sales_order', 'maintenance', 'offer_package'], true)) {
            return null;
        }

        if (! Schema::hasTable('inventory_cost_layers') || ! Schema::hasTable('inventory_cost_allocations')) {
            throw new \RuntimeException('Inventory costing tables are not available.');
        }

        $available = (float) InventoryCostLayer::query()
            ->where('product_id', $product->id)
            ->when($sizeColorId !== null && $sizeColorId > 0,
                fn ($query) => $query->where('size_color_id', $sizeColorId),
                fn ($query) => $query->whereNull('size_color_id'))
            ->where('remaining_quantity', '>', 0)
            ->sum('remaining_quantity');

        try {
            $cost = app(InventoryCostingService::class)->consumeCost(
                $product,
                $quantity,
                $referenceType ?? 'stock_out',
                $referenceId,
                $sizeColorId,
                $sizeId,
                $allowNegative,
            );

            return [
                'method' => $cost['method'],
                'unit_cost' => (float) $cost['unit_cost'],
                'total_cost' => (float) $cost['total_cost'],
                'cost_complete' => (bool) ($cost['cost_complete'] ?? true),
                'pending_quantity' => (float) ($cost['pending_quantity'] ?? 0),
            ];
        } catch (\Throwable $e) {
            Log::error('Inventory costing failed while creating outbound stock snapshot.', [
                'product_id' => $product->id,
                'quantity' => $quantity,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'available_cost_quantity' => $available,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function resolveRestorationUnitCost(
        int $productId,
        ?int $sizeColorId,
        ?string $referenceType,
        ?int $referenceId,
    ): ?float {
        if (! Schema::hasTable('product_stock_movements')) {
            return null;
        }

        $movement = ProductStockMovement::query()
            ->where('product_id', $productId)
            ->where('quantity', '<', 0)
            ->when($sizeColorId !== null && $sizeColorId > 0,
                fn ($query) => $query->where('size_color_id', $sizeColorId),
                fn ($query) => $query->whereNull('size_color_id'))
            ->when($referenceType, fn ($query) => $query->where('reference_type', $referenceType))
            ->when($referenceId, fn ($query) => $query->where('reference_id', $referenceId))
            ->whereNotNull('unit_cost')
            ->latest('id')
            ->first();

        if ($movement?->unit_cost !== null) {
            return (float) $movement->unit_cost;
        }

        if (! $referenceId || ! Schema::hasTable('inventory_cost_allocations')) {
            return null;
        }

        $allocations = InventoryCostAllocation::query()
            ->where('product_id', $productId)
            ->whereNotNull('inventory_cost_layer_id')
            ->when($sizeColorId !== null && $sizeColorId > 0,
                fn ($query) => $query->where('size_color_id', $sizeColorId),
                fn ($query) => $query->whereNull('size_color_id'))
            ->when($referenceType, fn ($query) => $query->where('reference_type', $referenceType))
            ->where('reference_id', $referenceId);
        $quantity = (float) (clone $allocations)->sum('quantity');

        return $quantity > 0
            ? (float) (clone $allocations)->sum('total_cost') / $quantity
            : null;
    }

    private function persistOutboundCostSnapshot(
        Product $product,
        int $quantity,
        ?string $referenceType,
        ?int $referenceId,
        ?array $cost,
        ?int $sizeColorId,
        ?int $sizeId,
    ): void {
        if (! $cost || ! $referenceId) {
            return;
        }

        $costComplete = (bool) ($cost['cost_complete'] ?? true);

        if ($referenceType === 'instant_sale' && Schema::hasTable('instant_sales') && Schema::hasColumn('instant_sales', 'inventory_total_cost')) {
            DB::table('instant_sales')
                ->where('id', $referenceId)
                ->update([
                    'inventory_cost_method' => $cost['method'],
                    'inventory_unit_cost' => $costComplete ? $cost['unit_cost'] : null,
                    'inventory_total_cost' => $costComplete ? $cost['total_cost'] : null,
                    'updated_at' => now(),
                ]);
        }

        if ($referenceType === 'maintenance' && Schema::hasTable('maintenance_products') && Schema::hasColumn('maintenance_products', 'inventory_total_cost')) {
            $maintenanceProduct = DB::table('maintenance_products')
                ->where('maintenance_id', $referenceId)
                ->where('product_id', $product->id)
                ->when($sizeColorId !== null,
                    fn ($query) => $query->where('size_color_id', $sizeColorId),
                    fn ($query) => $query->whereNull('size_color_id'))
                ->when($sizeId !== null,
                    fn ($query) => $query->where('size_id', $sizeId))
                ->where('quantity', $quantity)
                ->whereNull('inventory_total_cost')
                ->orderBy('id')
                ->first();

            if ($maintenanceProduct) {
                DB::table('maintenance_products')
                    ->where('id', $maintenanceProduct->id)
                    ->update([
                        'inventory_cost_method' => $cost['method'],
                        'inventory_unit_cost' => $cost['unit_cost'],
                        'inventory_total_cost' => $cost['total_cost'],
                        'updated_at' => now(),
                    ]);
            }

            if (Schema::hasTable('instant_sales') && Schema::hasColumn('instant_sales', 'maintenance_id')) {
                $instantSaleId = DB::table('instant_sales')
                    ->where('maintenance_id', $referenceId)
                    ->where('product_id', $product->id)
                    ->when($sizeColorId !== null,
                        fn ($query) => $query->where('size_color_id', $sizeColorId),
                        fn ($query) => $query->whereNull('size_color_id'))
                    ->when($sizeId !== null,
                        fn ($query) => $query->where('size_id', $sizeId))
                    ->where('quantity', $quantity)
                    ->whereNull('inventory_total_cost')
                    ->orderBy('id')
                    ->value('id');

                if ($instantSaleId) {
                    DB::table('instant_sales')
                        ->where('id', $instantSaleId)
                        ->update([
                            'inventory_cost_method' => $cost['method'],
                            'inventory_unit_cost' => $cost['unit_cost'],
                            'inventory_total_cost' => $cost['total_cost'],
                            'updated_at' => now(),
                        ]);
                }
            }
        }
    }

    private function refreshCloseoutStatus(int $productId, bool $reopen = false): void
    {
        $product = Product::query()->find($productId);
        if (! $product instanceof Product) {
            return;
        }

        $stock = $this->resolveDisplayStock($product->load('sizes.colorSizes'));
        $closeout = Closeout::query()->where('product_id', $productId)->first();
        if (! $closeout) {
            return;
        }

        if ($stock <= 0) {
            $closeout->update(['status' => 'archived']);
        } elseif ($reopen && $closeout->status === 'archived') {
            $closeout->update(['status' => 'ongoing']);
        }
    }
}
