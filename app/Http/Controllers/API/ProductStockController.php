<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductStockMovement;
use App\Models\SizeColor;
use App\Services\ProductStockService;
use App\Support\ApiImageUrl;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ProductStockController extends Controller
{
    public function __construct(
        private readonly ProductStockService $stockService
    ) {}

    public function adjust(Request $request)
    {
        try {
            $data = $request->validate([
                'product_id' => ['required', 'integer', 'exists:products,id'],
                'size_color_id' => ['nullable', 'integer', 'exists:size_colors,id'],
                'quantity' => ['required', 'integer', 'not_in:0'],
                'note' => ['nullable', 'string', 'max:500'],
            ]);

            $product = Product::query()->findOrFail($data['product_id']);
            $sizeColorId = isset($data['size_color_id']) ? (int) $data['size_color_id'] : null;

            if ($sizeColorId !== null) {
                $variant = SizeColor::query()->with('size')->findOrFail($sizeColorId);
                if ((int) ($variant->size?->itemId ?? 0) !== (int) $product->id) {
                    return response()->json([
                        'status' => 'error',
                        'message' => __('messages.validation_failed'),
                    ], 200);
                }
            } elseif ($this->stockService->productHasVariants($product->load('sizes.colorSizes'))) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.variant_required'),
                ], 200);
            }

            $delta = (int) $data['quantity'];
            $type = $delta > 0
                ? ProductStockMovement::TYPE_MANUAL_ADD
                : ProductStockMovement::TYPE_MANUAL_SET;

            $this->stockService->adjustStock(
                product: $product,
                quantityDelta: $delta,
                type: $type,
                sizeColorId: $sizeColorId,
                referenceType: 'manual_adjust',
                note: $data['note'] ?? null,
                userId: auth()->id() ? (int) auth()->id() : null,
            );

            $fresh = $product->fresh(['sizes.colorSizes']);

            return response()->json([
                'status' => 'success',
                'message' => __('messages.product_updated'),
                'product_stock' => $this->stockService->resolveDisplayStock($fresh),
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

    public function movements(Request $request)
    {
        try {
            $data = $request->validate([
                'product_id' => ['required', 'integer', 'exists:products,id'],
                'date_from' => ['nullable', 'date'],
                'date_to' => ['nullable', 'date'],
                'type' => ['nullable', 'string', 'in:'.implode(',', [
                    ProductStockMovement::TYPE_PURCHASE,
                    ProductStockMovement::TYPE_BILL_QUANTITY,
                    ProductStockMovement::TYPE_SALE,
                    ProductStockMovement::TYPE_SALE_CANCEL,
                    ProductStockMovement::TYPE_DESTRUCTION,
                    ProductStockMovement::TYPE_RETURN,
                    ProductStockMovement::TYPE_MANUAL_ADD,
                    ProductStockMovement::TYPE_MANUAL_SET,
                    ProductStockMovement::TYPE_IMPORT,
                    ProductStockMovement::TYPE_ASSEMBLY_COMPONENT,
                    ProductStockMovement::TYPE_ASSEMBLY_OUTPUT,
                    ProductStockMovement::TYPE_DISASSEMBLY_COMPONENT,
                    ProductStockMovement::TYPE_DISASSEMBLY_OUTPUT,
                    ProductStockMovement::TYPE_PRICE_UPDATE,
                ])],
                'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            ]);

            $perPage = min(max((int) ($data['per_page'] ?? 50), 1), 100);
            $summary = $this->stockService->movementSummary(
                (int) $data['product_id'],
                $data['date_from'] ?? null,
                $data['date_to'] ?? null,
            );

            $query = ProductStockMovement::query()
                ->with([
                    'size:id,size',
                    'sizeColor:id,colorAr,sizeId',
                    'creator:id,name',
                ])
                ->where('product_id', (int) $data['product_id'])
                ->orderByDesc('created_at');

            if (! empty($data['date_from'])) {
                $query->whereDate('created_at', '>=', $data['date_from']);
            }
            if (! empty($data['date_to'])) {
                $query->whereDate('created_at', '<=', $data['date_to']);
            }
            if (! empty($data['type'])) {
                $query->where('type', $data['type']);
            }

            $paginated = $query->paginate($perPage);
            $rows = $paginated->getCollection()->map(function (ProductStockMovement $m) {
                $invoiceNumber = null;
                if ($m->reference_type === 'instant_sale' && $m->reference_id) {
                    $invoiceNumber = '#'.$m->reference_id;
                } elseif (in_array($m->reference_type, ['product_assembly', 'product_disassembly'], true) && $m->reference_id) {
                    $invoiceNumber = '#'.$m->reference_id;
                }

                return [
                    'id' => $m->id,
                    'type' => $m->type,
                    'quantity' => $m->quantity,
                    'stock_before' => $m->stock_before,
                    'stock_after' => $m->stock_after,
                    'unit_cost' => $m->unit_cost,
                    'total_cost' => $m->total_cost,
                    'size' => $m->size?->size,
                    'color_ar' => $m->sizeColor?->colorAr,
                    'note' => $m->note,
                    'reference_type' => $m->reference_type,
                    'reference_id' => $m->reference_id,
                    'invoice_number' => $invoiceNumber,
                    'created_by_name' => $m->creator?->name,
                    'created_at' => $m->created_at?->format('Y-m-d H:i'),
                ];
            });

            return response()->json([
                'status' => 'success',
                'summary' => $summary,
                'movements' => $rows->values(),
                'pagination' => [
                    'current_page' => $paginated->currentPage(),
                    'last_page' => $paginated->lastPage(),
                    'per_page' => $paginated->perPage(),
                    'total' => $paginated->total(),
                ],
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }
}
