<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductAssemblyOperation;
use App\Models\ProductAssemblyOperationItem;
use App\Models\ProductAssemblyRecipe;
use App\Models\ProductAssemblyRecipeItem;
use App\Models\ProductStockMovement;
use App\Models\SizeColor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductAssemblyService
{
    public function __construct(
        private readonly ProductStockService $stockService
    ) {}

    /**
     * @param array<int, array{product_id:int, quantity:int, size_color_id?:int|null}> $components
     */
    public function assemble(
        int $targetProductId,
        ?int $targetSizeColorId,
        int $quantity,
        array $components,
        ?string $note,
        ?int $userId,
    ): ProductAssemblyOperation {
        $this->ensurePositiveQuantity($quantity);
        $targetProduct = Product::query()->findOrFail($targetProductId);
        $this->validateVariantBelongsToProduct($targetProduct, $targetSizeColorId);
        $this->ensureVariantChosenWhenNeeded($targetProduct, $targetSizeColorId);

        return DB::transaction(function () use ($targetProduct, $targetSizeColorId, $quantity, $components, $note, $userId) {
            $normalized = $this->normalizeComponents($components);
            $unitCost = $this->calculateRecipeUnitCost($normalized);

            foreach ($normalized as $component) {
                $required = (int) $component['quantity'] * $quantity;
                $this->ensureAvailableStock(
                    (int) $component['product_id'],
                    $component['size_color_id'],
                    $required,
                );
            }

            $recipe = $this->createRecipe(
                targetProduct: $targetProduct,
                targetSizeColorId: $targetSizeColorId,
                components: $normalized,
                unitCost: $unitCost,
                userId: $userId,
            );

            $operation = $this->createOperation(
                recipe: $recipe,
                type: ProductAssemblyOperation::TYPE_ASSEMBLE,
                quantity: $quantity,
                unitCost: $unitCost,
                note: $note,
                userId: $userId,
            );

            foreach ($recipe->items as $item) {
                $totalQuantity = (int) $item->quantity_per_unit * $quantity;
                $componentProduct = Product::query()->findOrFail($item->component_product_id);

                ProductAssemblyOperationItem::create([
                    'operation_id' => $operation->id,
                    'component_product_id' => $item->component_product_id,
                    'component_size_color_id' => $item->component_size_color_id,
                    'quantity_per_unit' => $item->quantity_per_unit,
                    'total_quantity' => $totalQuantity,
                    'unit_cost' => $item->unit_cost,
                    'total_cost' => $item->unit_cost * $totalQuantity,
                ]);

                $this->stockService->adjustStock(
                    product: $componentProduct,
                    quantityDelta: -$totalQuantity,
                    type: ProductStockMovement::TYPE_ASSEMBLY_COMPONENT,
                    sizeColorId: $item->component_size_color_id ? (int) $item->component_size_color_id : null,
                    referenceType: 'product_assembly',
                    referenceId: (int) $operation->id,
                    note: $this->stockNote('تركيب منتج - خصم مكوّن', $operation),
                    userId: $userId,
                );
            }

            $this->stockService->adjustStock(
                product: $targetProduct,
                quantityDelta: $quantity,
                type: ProductStockMovement::TYPE_ASSEMBLY_OUTPUT,
                sizeColorId: $targetSizeColorId,
                referenceType: 'product_assembly',
                referenceId: (int) $operation->id,
                note: $this->stockNote('تركيب منتج - زيادة المنتج الناتج', $operation),
                userId: $userId,
            );

            return $operation->fresh([
                'recipe.items.componentProduct',
                'recipe.targetProduct',
                'items.componentProduct',
            ]);
        });
    }

    public function disassemble(
        int $quantity,
        ?int $recipeId,
        ?int $targetProductId,
        ?string $note,
        ?int $userId,
    ): ProductAssemblyOperation {
        $this->ensurePositiveQuantity($quantity);
        $recipe = $this->resolveRecipe($recipeId, $targetProductId);

        return DB::transaction(function () use ($recipe, $quantity, $note, $userId) {
            $recipe->loadMissing('items.componentProduct', 'targetProduct');
            $targetProduct = $recipe->targetProduct;
            if (! $targetProduct instanceof Product) {
                throw ValidationException::withMessages([
                    'target_product_id' => ['المنتج الناتج غير موجود.'],
                ]);
            }

            $this->ensureAvailableStock(
                (int) $recipe->target_product_id,
                $recipe->target_size_color_id ? (int) $recipe->target_size_color_id : null,
                $quantity,
            );

            $operation = $this->createOperation(
                recipe: $recipe,
                type: ProductAssemblyOperation::TYPE_DISASSEMBLE,
                quantity: $quantity,
                unitCost: (float) $recipe->unit_cost,
                note: $note,
                userId: $userId,
            );

            $this->stockService->adjustStock(
                product: $targetProduct,
                quantityDelta: -$quantity,
                type: ProductStockMovement::TYPE_DISASSEMBLY_OUTPUT,
                sizeColorId: $recipe->target_size_color_id ? (int) $recipe->target_size_color_id : null,
                referenceType: 'product_disassembly',
                referenceId: (int) $operation->id,
                note: $this->stockNote('فك تركيب - خصم المنتج المركب', $operation),
                userId: $userId,
            );

            foreach ($recipe->items as $item) {
                $totalQuantity = (int) $item->quantity_per_unit * $quantity;
                $componentProduct = Product::query()->findOrFail($item->component_product_id);

                ProductAssemblyOperationItem::create([
                    'operation_id' => $operation->id,
                    'component_product_id' => $item->component_product_id,
                    'component_size_color_id' => $item->component_size_color_id,
                    'quantity_per_unit' => $item->quantity_per_unit,
                    'total_quantity' => $totalQuantity,
                    'unit_cost' => $item->unit_cost,
                    'total_cost' => $item->unit_cost * $totalQuantity,
                ]);

                $this->stockService->adjustStock(
                    product: $componentProduct,
                    quantityDelta: $totalQuantity,
                    type: ProductStockMovement::TYPE_DISASSEMBLY_COMPONENT,
                    sizeColorId: $item->component_size_color_id ? (int) $item->component_size_color_id : null,
                    referenceType: 'product_disassembly',
                    referenceId: (int) $operation->id,
                    note: $this->stockNote('فك تركيب - إرجاع مكوّن', $operation),
                    userId: $userId,
                );
            }

            return $operation->fresh([
                'recipe.items.componentProduct',
                'recipe.targetProduct',
                'items.componentProduct',
            ]);
        });
    }

    public function latestRecipes(int $limit = 100)
    {
        return ProductAssemblyRecipe::query()
            ->with([
                'targetProduct:id,nameAr,stock,product_code',
                'targetSizeColor:id,colorAr,sizeId,stock',
                'items.componentProduct:id,nameAr,stock,product_code',
                'items.componentSizeColor:id,colorAr,sizeId,stock',
            ])
            ->where('is_active', true)
            ->latest()
            ->limit($limit)
            ->get();
    }

    /**
     * @param array<int, array<string, mixed>> $components
     * @return array<int, array{product_id:int, quantity:int, size_color_id:int|null, unit_cost:float}>
     */
    private function normalizeComponents(array $components): array
    {
        $merged = [];

        foreach ($components as $component) {
            $productId = (int) ($component['product_id'] ?? 0);
            $quantity = (int) ($component['quantity'] ?? 0);
            $sizeColorId = isset($component['size_color_id']) && $component['size_color_id'] !== ''
                ? (int) $component['size_color_id']
                : null;

            if ($productId <= 0 || $quantity <= 0) {
                throw ValidationException::withMessages([
                    'components' => ['كل مكوّن يحتاج منتج وكمية أكبر من صفر.'],
                ]);
            }

            $product = Product::query()->findOrFail($productId);
            $this->validateVariantBelongsToProduct($product, $sizeColorId);
            $this->ensureVariantChosenWhenNeeded($product, $sizeColorId);

            $key = $productId.'::'.($sizeColorId ?? 'main');
            if (! isset($merged[$key])) {
                $merged[$key] = [
                    'product_id' => $productId,
                    'quantity' => 0,
                    'size_color_id' => $sizeColorId,
                    'unit_cost' => $this->latestPurchaseCost($product),
                ];
            }
            $merged[$key]['quantity'] += $quantity;
        }

        if ($merged === []) {
            throw ValidationException::withMessages([
                'components' => ['أضف مكوّناً واحداً على الأقل.'],
            ]);
        }

        return array_values($merged);
    }

    /**
     * @param array<int, array{quantity:int, unit_cost:float}> $components
     */
    private function calculateRecipeUnitCost(array $components): float
    {
        $sum = 0.0;
        foreach ($components as $component) {
            $sum += ((float) $component['unit_cost']) * ((int) $component['quantity']);
        }

        return round($sum, 2);
    }

    /**
     * @param array<int, array{product_id:int, quantity:int, size_color_id:int|null, unit_cost:float}> $components
     */
    private function createRecipe(Product $targetProduct, ?int $targetSizeColorId, array $components, float $unitCost, ?int $userId): ProductAssemblyRecipe
    {
        $recipe = ProductAssemblyRecipe::create([
            'target_product_id' => $targetProduct->id,
            'target_size_color_id' => $targetSizeColorId,
            'name' => $targetProduct->nameAr,
            'unit_cost' => $unitCost,
            'is_active' => true,
            'created_by' => $userId,
        ]);

        foreach ($components as $component) {
            ProductAssemblyRecipeItem::create([
                'recipe_id' => $recipe->id,
                'component_product_id' => $component['product_id'],
                'component_size_color_id' => $component['size_color_id'],
                'quantity_per_unit' => $component['quantity'],
                'unit_cost' => $component['unit_cost'],
            ]);
        }

        return $recipe->fresh(['items']);
    }

    private function createOperation(
        ProductAssemblyRecipe $recipe,
        string $type,
        int $quantity,
        float $unitCost,
        ?string $note,
        ?int $userId,
    ): ProductAssemblyOperation {
        return ProductAssemblyOperation::create([
            'recipe_id' => $recipe->id,
            'operation_type' => $type,
            'target_product_id' => $recipe->target_product_id,
            'target_size_color_id' => $recipe->target_size_color_id,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'total_cost' => round($unitCost * $quantity, 2),
            'note' => $note,
            'created_by' => $userId,
        ]);
    }

    private function resolveRecipe(?int $recipeId, ?int $targetProductId): ProductAssemblyRecipe
    {
        $query = ProductAssemblyRecipe::query()->where('is_active', true);
        if ($recipeId !== null && $recipeId > 0) {
            $recipe = $query->find($recipeId);
        } elseif ($targetProductId !== null && $targetProductId > 0) {
            $recipe = $query->where('target_product_id', $targetProductId)->latest()->first();
        } else {
            $recipe = null;
        }

        if (! $recipe instanceof ProductAssemblyRecipe) {
            throw ValidationException::withMessages([
                'recipe_id' => ['لم يتم العثور على وصفة تركيب لهذا المنتج.'],
            ]);
        }

        return $recipe;
    }

    private function ensureAvailableStock(int $productId, ?int $sizeColorId, int $required): void
    {
        $product = Product::query()->findOrFail($productId);
        $available = $this->stockService->resolveAvailableStock($product, $sizeColorId);
        if ($available < $required) {
            throw ValidationException::withMessages([
                'stock' => ["المخزون غير كافٍ للمنتج {$product->nameAr}. المتوفر {$available} والمطلوب {$required}."],
            ]);
        }
    }

    private function ensurePositiveQuantity(int $quantity): void
    {
        if ($quantity < 1) {
            throw ValidationException::withMessages([
                'quantity' => ['الكمية يجب أن تكون أكبر من صفر.'],
            ]);
        }
    }

    private function ensureVariantChosenWhenNeeded(Product $product, ?int $sizeColorId): void
    {
        if ($sizeColorId === null && $this->stockService->productHasVariants($product->load('sizes.colorSizes'))) {
            throw ValidationException::withMessages([
                'size_color_id' => ["اختر المقاس/اللون للمنتج {$product->nameAr}."],
            ]);
        }
    }

    private function validateVariantBelongsToProduct(Product $product, ?int $sizeColorId): void
    {
        if ($sizeColorId === null || $sizeColorId <= 0) {
            return;
        }

        $variant = SizeColor::query()->with('size')->findOrFail($sizeColorId);
        if ((int) ($variant->size?->itemId ?? 0) !== (int) $product->id) {
            throw ValidationException::withMessages([
                'size_color_id' => ['المقاس/اللون لا يتبع المنتج المحدد.'],
            ]);
        }
    }

    private function latestPurchaseCost(Product $product): float
    {
        return (float) $product->purchasePrices()->latest('id')->value('price');
    }

    private function stockNote(string $prefix, ProductAssemblyOperation $operation): string
    {
        return $prefix.' #'.$operation->id;
    }
}
