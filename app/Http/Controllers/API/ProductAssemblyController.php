<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ProductAssemblyOperation;
use App\Models\ProductAssemblyRecipe;
use App\Services\ProductAssemblyService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ProductAssemblyController extends Controller
{
    public function __construct(
        private readonly ProductAssemblyService $assemblyService
    ) {}

    public function recipes(Request $request)
    {
        try {
            $limit = min(max((int) $request->input('limit', 100), 1), 200);

            return response()->json([
                'status' => 'success',
                'recipes' => $this->assemblyService->latestRecipes($limit)
                    ->map(fn (ProductAssemblyRecipe $recipe) => $this->formatRecipe($recipe))
                    ->values(),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function assemble(Request $request)
    {
        try {
            $data = $request->validate([
                'target_product_id' => ['required', 'integer', 'exists:products,id'],
                'target_size_color_id' => ['nullable', 'integer', 'exists:size_colors,id'],
                'quantity' => ['required', 'integer', 'min:1'],
                'note' => ['nullable', 'string', 'max:500'],
                'components' => ['required', 'array', 'min:1'],
                'components.*.product_id' => ['required', 'integer', 'exists:products,id'],
                'components.*.size_color_id' => ['nullable', 'integer', 'exists:size_colors,id'],
                'components.*.quantity' => ['required', 'integer', 'min:1'],
            ]);

            $operation = $this->assemblyService->assemble(
                targetProductId: (int) $data['target_product_id'],
                targetSizeColorId: isset($data['target_size_color_id']) ? (int) $data['target_size_color_id'] : null,
                quantity: (int) $data['quantity'],
                components: $data['components'],
                note: $data['note'] ?? null,
                userId: auth()->id() ? (int) auth()->id() : null,
            );

            return response()->json([
                'status' => 'success',
                'message' => 'تم تنفيذ تركيب المنتج بنجاح.',
                'operation' => $this->formatOperation($operation),
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function disassemble(Request $request)
    {
        try {
            $data = $request->validate([
                'recipe_id' => ['nullable', 'integer', 'exists:product_assembly_recipes,id'],
                'target_product_id' => ['nullable', 'integer', 'exists:products,id'],
                'quantity' => ['required', 'integer', 'min:1'],
                'note' => ['nullable', 'string', 'max:500'],
            ]);

            $operation = $this->assemblyService->disassemble(
                quantity: (int) $data['quantity'],
                recipeId: isset($data['recipe_id']) ? (int) $data['recipe_id'] : null,
                targetProductId: isset($data['target_product_id']) ? (int) $data['target_product_id'] : null,
                note: $data['note'] ?? null,
                userId: auth()->id() ? (int) auth()->id() : null,
            );

            return response()->json([
                'status' => 'success',
                'message' => 'تم تنفيذ فك تركيب المنتج بنجاح.',
                'operation' => $this->formatOperation($operation),
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    private function formatOperation(ProductAssemblyOperation $operation): array
    {
        $operation->loadMissing([
            'targetProduct',
            'recipe.items.componentProduct',
            'recipe.targetProduct',
            'items.componentProduct',
        ]);

        return [
            'id' => $operation->id,
            'recipe_id' => $operation->recipe_id,
            'operation_type' => $operation->operation_type,
            'target_product_id' => $operation->target_product_id,
            'target_product_name' => $operation->targetProduct?->nameAr ?? $operation->recipe?->targetProduct?->nameAr,
            'target_size_color_id' => $operation->target_size_color_id,
            'quantity' => $operation->quantity,
            'unit_cost' => (float) $operation->unit_cost,
            'total_cost' => (float) $operation->total_cost,
            'note' => $operation->note,
            'items' => $operation->items->map(fn ($item) => [
                'component_product_id' => $item->component_product_id,
                'component_product_name' => $item->componentProduct?->nameAr,
                'component_size_color_id' => $item->component_size_color_id,
                'quantity_per_unit' => (float) $item->quantity_per_unit,
                'total_quantity' => (float) $item->total_quantity,
                'unit_cost' => (float) $item->unit_cost,
                'total_cost' => (float) $item->total_cost,
            ])->values(),
            'created_at' => $operation->created_at?->format('Y-m-d H:i'),
        ];
    }

    private function formatRecipe(ProductAssemblyRecipe $recipe): array
    {
        return [
            'id' => $recipe->id,
            'target_product_id' => $recipe->target_product_id,
            'target_product_name' => $recipe->targetProduct?->nameAr,
            'target_product_stock' => $recipe->targetProduct?->stock,
            'target_product_code' => $recipe->targetProduct?->product_code,
            'target_size_color_id' => $recipe->target_size_color_id,
            'target_color_ar' => $recipe->targetSizeColor?->colorAr,
            'unit_cost' => (float) $recipe->unit_cost,
            'items' => $recipe->items->map(fn ($item) => [
                'component_product_id' => $item->component_product_id,
                'component_product_name' => $item->componentProduct?->nameAr,
                'component_product_stock' => $item->componentProduct?->stock,
                'component_product_code' => $item->componentProduct?->product_code,
                'component_size_color_id' => $item->component_size_color_id,
                'component_color_ar' => $item->componentSizeColor?->colorAr,
                'quantity_per_unit' => (float) $item->quantity_per_unit,
                'unit_cost' => (float) $item->unit_cost,
            ])->values(),
            'created_at' => $recipe->created_at?->format('Y-m-d H:i'),
        ];
    }
}
