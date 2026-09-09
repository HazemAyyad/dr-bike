<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\BillItem;
use App\Models\InstantSale;
use App\Models\Product;
use App\Models\ProductStockMovement;
use App\Models\PurchaseAmanatStock;
use App\Models\PurchaseReceiptItem;
use App\Models\ReturnModel;
use App\Models\SalesOrder;
use App\Models\SalesReturn;
use App\Models\SizeColor;
use App\Services\ProductStockService;
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
                'note' => ['required', 'string', 'max:500'],
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
                    ProductStockMovement::TYPE_PURCHASE_RETURN,
                    ProductStockMovement::TYPE_PURCHASE_RETURN_CANCEL,
                    ProductStockMovement::TYPE_MAINTENANCE,
                    ProductStockMovement::TYPE_BILL_QUANTITY,
                    ProductStockMovement::TYPE_SALE,
                    ProductStockMovement::TYPE_SALE_CANCEL,
                    ProductStockMovement::TYPE_DESTRUCTION,
                    ProductStockMovement::TYPE_RETURN,
                    ProductStockMovement::TYPE_SALES_RETURN,
                    ProductStockMovement::TYPE_SALES_RETURN_CANCEL,
                    ProductStockMovement::TYPE_MANUAL_ADD,
                    ProductStockMovement::TYPE_MANUAL_SET,
                    ProductStockMovement::TYPE_IMPORT,
                    ProductStockMovement::TYPE_ASSEMBLY_COMPONENT,
                    ProductStockMovement::TYPE_ASSEMBLY_OUTPUT,
                    ProductStockMovement::TYPE_DISASSEMBLY_COMPONENT,
                    ProductStockMovement::TYPE_DISASSEMBLY_OUTPUT,
                    ProductStockMovement::TYPE_PRICE_UPDATE,
                    ProductStockMovement::TYPE_PRODUCT_UPDATE,
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
            $canViewCostPrice = $request->user()?->canViewCostPrice() ?? false;
            $canViewPurchases = $this->canAccessSection($request, 'Purchasing Section');
            $canViewSales = $this->canAccessSection($request, 'Sales');
            $documentMaps = $this->loadDocumentMaps($paginated->getCollection());
            $rows = $paginated->getCollection()->map(function (ProductStockMovement $m) use ($documentMaps, $canViewCostPrice, $canViewPurchases, $canViewSales) {
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
                    'unit_cost' => $canViewCostPrice ? $m->unit_cost : null,
                    'total_cost' => $canViewCostPrice ? $m->total_cost : null,
                    'size' => $m->size?->size,
                    'color_ar' => $m->sizeColor?->colorAr,
                    'note' => $m->note,
                    'reference_type' => $m->reference_type,
                    'reference_id' => $m->reference_id,
                    'invoice_number' => $invoiceNumber,
                    'created_by_name' => $m->creator?->name,
                    'created_at' => $m->created_at?->format('Y-m-d H:i'),
                    'document' => $this->movementDocument(
                        $m,
                        $documentMaps,
                        $canViewCostPrice,
                        $canViewPurchases,
                        $canViewSales,
                    ),
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

    /** @return array<string, \Illuminate\Support\Collection<int, mixed>> */
    private function loadDocumentMaps($movements): array
    {
        $ids = static fn (string $type) => $movements
            ->where('reference_type', $type)
            ->pluck('reference_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $salesReturnIds = $ids('sales_return')->merge($ids('sales_return_cancel'))->unique();
        $purchaseReturnIds = $ids('purchase_return')->merge($ids('purchase_return_cancel'))->unique();

        return [
            'instant_sale' => InstantSale::query()
                ->with(['buyerCustomer', 'seller', 'paymentBox'])
                ->whereIn('id', $ids('instant_sale'))->get()->keyBy('id'),
            'sales_order' => SalesOrder::query()
                ->with('customer')
                ->whereIn('id', $ids('sales_order'))->get()->keyBy('id'),
            'purchase_receipt_item' => PurchaseReceiptItem::query()
                ->with(['receipt.bill.seller', 'receipt.bill.customer'])
                ->whereIn('id', $ids('purchase_receipt_item'))->get()->keyBy('id'),
            'purchase_amanat_purchase' => PurchaseAmanatStock::query()
                ->with(['bill.seller', 'bill.customer'])
                ->whereIn('id', $ids('purchase_amanat_purchase'))->get()->keyBy('id'),
            'purchase_issue_resolution' => BillItem::query()
                ->with(['bill.seller', 'bill.customer'])
                ->whereIn('id', $ids('purchase_issue_resolution'))->get()->keyBy('id'),
            'sales_return' => SalesReturn::query()
                ->with(['customer', 'seller', 'refundBox', 'instantSale', 'salesOrder'])
                ->whereIn('id', $salesReturnIds)->get()->keyBy('id'),
            'purchase_return' => ReturnModel::query()
                ->with(['seller', 'customer'])
                ->whereIn('id', $purchaseReturnIds)->get()->keyBy('id'),
        ];
    }

    /** @param array<string, \Illuminate\Support\Collection<int, mixed>> $maps */
    private function movementDocument(
        ProductStockMovement $movement,
        array $maps,
        bool $canViewCostPrice,
        bool $canViewPurchases,
        bool $canViewSales,
    ): ?array {
        $type = (string) $movement->reference_type;
        $id = (int) $movement->reference_id;
        if ($id <= 0) {
            return null;
        }

        if ($type === 'instant_sale') {
            if (! $canViewSales) {
                return null;
            }
            $sale = $maps['instant_sale']->get($id);
            if (! $sale) {
                return null;
            }
            $total = (float) ($sale->total_cost ?? 0);
            $paid = (float) ($sale->payment_box_value ?? 0);

            return $this->documentPayload(
                'instant_sale', (int) $sale->id, $sale->serial_number ?: '#'.$sale->id,
                $sale->buyerCustomer?->name ?? $sale->seller?->name ?? $sale->buyer_name,
                $sale->status, (float) ($sale->cost ?? 0), $total, $paid,
                max(0, $total - $paid), $sale->payment_box_name ?? $sale->paymentBox?->name,
                $sale->notes, null, null
            );
        }

        if ($type === 'sales_order') {
            if (! $canViewSales) {
                return null;
            }
            $order = $maps['sales_order']->get($id);
            if (! $order) {
                return null;
            }
            $total = (float) ($order->total ?? $order->calculated_total ?? 0);
            $paid = (float) ($order->payment_amount ?? 0) + (float) ($order->delivery_settled_amount ?? 0);

            return $this->documentPayload(
                'sales_order', (int) $order->id, $order->serial_number ?: '#'.$order->id,
                $order->customer?->name ?? $order->customer_name, $order->status,
                null, $total, $paid, max(0, $total - $paid), null,
                $order->notes, null, null
            );
        }

        if ($type === 'purchase_receipt_item') {
            if (! $canViewPurchases) {
                return null;
            }
            $item = $maps['purchase_receipt_item']->get($id);
            $bill = $item?->receipt?->bill;
            if (! $item || ! $bill) {
                return null;
            }
            $billTotal = (float) ($bill->final_total ?? $bill->total ?? 0);
            $paid = (float) ($bill->paid_amount ?? 0);

            return $this->documentPayload(
                'purchase', (int) $bill->id, '#'.$bill->id,
                $bill->seller?->name ?? $bill->customer?->name,
                $bill->workflow_status ?? $bill->status,
                $canViewCostPrice ? (float) $item->unit_price : null,
                $canViewCostPrice ? (float) $item->accepted_quantity * (float) $item->unit_price : null,
                $canViewCostPrice ? $paid : null,
                $canViewCostPrice ? max(0, $billTotal - $paid) : null, null,
                $item->notes ?? $bill->notes, $item->reason, $item->receipt?->receipt_number
            );
        }

        if ($type === 'purchase_amanat_purchase') {
            if (! $canViewPurchases) {
                return null;
            }
            $amanat = $maps['purchase_amanat_purchase']->get($id);
            $bill = $amanat?->bill;
            if (! $amanat || ! $bill) {
                return null;
            }
            $billTotal = (float) ($bill->final_total ?? $bill->total ?? 0);
            $paid = (float) ($bill->paid_amount ?? 0);
            $unitPrice = (float) ($amanat->negotiated_unit_price ?? 0);

            return $this->documentPayload(
                'purchase', (int) $bill->id, '#'.$bill->id,
                $bill->seller?->name ?? $bill->customer?->name,
                $bill->workflow_status ?? $bill->status,
                $canViewCostPrice ? $unitPrice : null,
                $canViewCostPrice ? (float) $amanat->quantity * $unitPrice : null,
                $canViewCostPrice ? $paid : null,
                $canViewCostPrice ? max(0, $billTotal - $paid) : null,
                null, $amanat->notes, null, null
            );
        }

        if ($type === 'purchase_issue_resolution') {
            if (! $canViewPurchases) {
                return null;
            }
            $item = $maps['purchase_issue_resolution']->get($id);
            $bill = $item?->bill;
            if (! $item || ! $bill) {
                return null;
            }
            $billTotal = (float) ($bill->final_total ?? $bill->total ?? 0);
            $paid = (float) ($bill->paid_amount ?? 0);
            $unitPrice = (float) ($item->final_unit_price ?? $item->price ?? 0);

            return $this->documentPayload(
                'purchase', (int) $bill->id, '#'.$bill->id,
                $bill->seller?->name ?? $bill->customer?->name,
                $bill->workflow_status ?? $bill->status,
                $canViewCostPrice ? $unitPrice : null,
                null, $canViewCostPrice ? $paid : null,
                $canViewCostPrice ? max(0, $billTotal - $paid) : null,
                null, $bill->notes, null, null
            );
        }

        if (in_array($type, ['sales_return', 'sales_return_cancel'], true)) {
            if (! $canViewSales) {
                return null;
            }
            $return = $maps['sales_return']->get($id);
            if (! $return) {
                return null;
            }

            return $this->documentPayload(
                'sales_return', (int) $return->id, $return->serial_number ?: '#'.$return->id,
                $return->customer?->name ?? $return->seller?->name, $return->status,
                null, (float) $return->total_amount, (float) $return->cash_refund_amount,
                (float) $return->credit_amount, $return->refundBox?->name,
                $return->note, $return->cancellation_reason,
                $return->instantSale?->serial_number ?? $return->salesOrder?->serial_number
            );
        }

        if (in_array($type, ['purchase_return', 'purchase_return_cancel'], true)) {
            if (! $canViewPurchases) {
                return null;
            }
            $return = $maps['purchase_return']->get($id);
            if (! $return) {
                return null;
            }

            return $this->documentPayload(
                'purchase_return', (int) $return->id, $return->number ?: '#'.$return->id,
                $return->seller?->name ?? $return->customer?->name, $return->status,
                null, $canViewCostPrice ? (float) $return->total : null,
                $canViewCostPrice ? (float) $return->settled_amount : null,
                $canViewCostPrice ? max(0, (float) $return->total - (float) $return->settled_amount) : null, null,
                $return->note ?? $return->notes,
                $return->reason ?? $return->cancellation_reason,
                $return->bill_id ? '#'.$return->bill_id : null
            );
        }

        return null;
    }

    private function canAccessSection(Request $request, string $permission): bool
    {
        $user = $request->user();
        if (! $user) {
            return false;
        }
        if ($user->type === 'admin') {
            return true;
        }
        if ($user->type !== 'employee' || ! $user->employee) {
            return false;
        }

        return $user->employee->permissions()
            ->whereHas('permission', fn ($query) => $query->where('name_en', $permission))
            ->exists();
    }

    private function documentPayload(
        string $type,
        int $id,
        string $number,
        ?string $partyName,
        ?string $status,
        ?float $unitPrice,
        ?float $total,
        ?float $paid,
        ?float $remaining,
        ?string $boxName,
        ?string $note,
        ?string $reason,
        ?string $sourceNumber,
    ): array {
        return [
            'type' => $type,
            'id' => $id,
            'number' => $number,
            'party_name' => $partyName,
            'status' => $status,
            'unit_price' => $unitPrice,
            'total' => $total,
            'paid' => $paid,
            'remaining' => $remaining,
            'box_name' => $boxName,
            'note' => $note,
            'reason' => $reason,
            'source_number' => $sourceNumber,
        ];
    }
}
