<?php

namespace App\Services;

use App\Models\InstantSale;
use App\Models\InstantSaleRevision;
use App\Models\InventoryAdjustment;
use App\Models\InventoryCostAllocation;
use App\Models\InventoryCostLayer;
use App\Models\Product;
use App\Models\ProductStockMovement;
use App\Models\SizeColor;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class MissingSaleCostService
{
    private const EPSILON = 0.0001;

    public function __construct(private InventoryCostingService $costing) {}

    public function preview(User $actor, int $productId, ?int $variantId, float $unitCost, string $currency): array
    {
        $this->authorize($actor);
        $product = Product::query()->findOrFail($productId);
        $this->identity($product, $variantId);

        return $this->buildPreview($product, $variantId, $unitCost, $currency);
    }

    public function apply(User $actor, int $productId, ?int $variantId, float $unitCost,
        string $currency, array $saleIds, string $token, string $reason): array
    {
        $this->authorize($actor);
        if (trim($reason) === '' || mb_strlen($reason) > 190 || $saleIds === []
            || count(array_unique($saleIds)) !== count($saleIds)) {
            $this->fail('حدد المبيعات وأدخل سبب التصحيح.');
        }

        return DB::transaction(function () use ($actor, $productId, $variantId, $unitCost, $currency, $saleIds, $token, $reason) {
            $product = Product::query()->lockForUpdate()->findOrFail($productId);
            $sizeId = $this->identity($product, $variantId);
            // Parent invoices are locked too: a concurrent cancellation must not
            // be able to turn this review into a correction of a cancelled sale.
            $roots = InstantSale::query()->where('product_id', $productId)
                ->when($variantId, fn ($q) => $q->where('size_color_id', $variantId), fn ($q) => $q->whereNull('size_color_id'))
                ->get(['id', 'parent_id'])->map(fn ($sale) => $sale->parent_id ?: $sale->id)->unique()->sort();
            $invoices = InstantSale::query()->whereIn('id', $roots)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $preview = $this->buildPreview($product, $variantId, $unitCost, $currency, true);
            if (! hash_equals($preview['review_token'], $token)) {
                $this->fail('تغيرت بيانات المبيعات أو التكلفة. افتح المراجعة مجددًا قبل التأكيد.');
            }
            $selected = collect($preview['sales'])->whereIn('id', array_map('intval', $saleIds));
            if ($selected->count() !== count($saleIds) || $selected->contains(fn ($row) => ! $row['eligible'])) {
                $this->fail('تتضمن المراجعة مبيعات غير مؤهلة أو تغيرت تكلفتها.');
            }
            // Even a partially repaired invoice must respect a closed period;
            // its remaining missing lines may prevent ledger projection below.
            if (Schema::hasTable('accounting_periods')) {
                foreach ($selected->pluck('invoice_id')->unique()->sort() as $invoiceId) {
                    $date = $invoices->get($invoiceId)->created_at->toDateString();
                    $period = \App\Models\AccountingPeriod::query()
                        ->whereDate('starts_at', '<=', $date)->whereDate('ends_at', '>=', $date)
                        ->lockForUpdate()->first();
                    if ($period?->isClosed()) {
                        $this->fail('الفترة المحاسبية مغلقة ولا يمكن تعديل تكلفة مبيعات تاريخ '.$date.'.');
                    }
                }
            }
            $currency = $this->costing->normalizeCurrency($currency);
            $physical = $variantId ? (float) SizeColor::findOrFail($variantId)->stock : (float) $product->stock;
            $adjustment = InventoryAdjustment::create([
                'reference' => 'SCC-'.strtoupper((string) \Illuminate\Support\Str::ulid()),
                'product_id' => $productId, 'size_id' => $sizeId, 'size_color_id' => $variantId,
                'adjustment_type' => InventoryAdjustment::TYPE_SALE_COST_INITIALIZATION,
                'stock_before' => $physical, 'stock_after' => $physical, 'quantity_difference' => 0,
                'old_unit_cost' => null, 'new_unit_cost' => $unitCost,
                'old_value' => 0, 'new_value' => 0, 'value_difference' => 0,
                'currency' => $currency, 'costing_method' => $this->costing->currentMethod(),
                'reason' => trim($reason), 'created_by' => $actor->id,
            ]);
            $parentIds = [];
            foreach ($selected as $row) {
                $sale = InstantSale::query()->lockForUpdate()->findOrFail($row['id']);
                $before = $sale->only(['inventory_cost_method', 'inventory_unit_cost', 'inventory_total_cost']);
                $allocations = $this->allocations($sale, true);
                $layer = InventoryCostLayer::create([
                    'product_id' => $productId, 'size_id' => $sizeId, 'size_color_id' => $variantId,
                    'quantity' => $row['missing_quantity'], 'remaining_quantity' => 0,
                    'unit_cost' => $unitCost, 'original_unit_cost' => $unitCost, 'currency' => $currency,
                    'source_type' => 'manual_sold_cost', 'source_id' => $adjustment->id,
                    'idempotency_key' => 'sold-cost:'.$sale->id,
                    'effective_at' => $sale->created_at,
                ]);
                $pending = $allocations->whereNull('inventory_cost_layer_id');
                foreach ($pending as $allocation) {
                    $allocation->update(['inventory_cost_layer_id' => $layer->id,
                        'unit_cost' => $unitCost, 'total_cost' => round($allocation->quantity * $unitCost, 6)]);
                }
                $unallocated = round((float) $sale->quantity - (float) $allocations->sum('quantity'), 4);
                if ($unallocated > self::EPSILON) {
                    InventoryCostAllocation::create([
                        'inventory_cost_layer_id' => $layer->id, 'product_id' => $productId,
                        'size_id' => $sizeId, 'size_color_id' => $variantId,
                        'quantity' => $unallocated, 'unit_cost' => $unitCost,
                        'total_cost' => round($unallocated * $unitCost, 6),
                        'method' => $this->costing->currentMethod(),
                        'reference_type' => 'instant_sale', 'reference_id' => $sale->id,
                    ]);
                }
                $method = (string) ($allocations->first()?->method ?: $this->costing->currentMethod());
                $after = ['inventory_cost_method' => $method,
                    'inventory_unit_cost' => round($row['new_total_cost'] / $sale->quantity, 6),
                    'inventory_total_cost' => $row['new_total_cost']];
                DB::table('instant_sales')->where('id', $sale->id)->update(array_merge($after, ['updated_at' => now()]));
                $movements = ProductStockMovement::query()->where('product_id', $productId)
                    ->where('reference_type', 'instant_sale')->where('reference_id', $sale->id)
                    ->when($variantId, fn ($q) => $q->where('size_color_id', $variantId), fn ($q) => $q->whereNull('size_color_id'))
                    ->where('quantity', '<', 0)->lockForUpdate()->get();
                foreach ($movements as $movement) {
                    $movement->update(['unit_cost' => $after['inventory_unit_cost'],
                        'total_cost' => round(abs($movement->quantity) * $after['inventory_unit_cost'], 6), 'costing_method' => $method]);
                }
                ProductStockMovement::create([
                    'product_id' => $productId, 'size_id' => $sizeId, 'size_color_id' => $variantId,
                    'type' => ProductStockMovement::TYPE_SALE_COST_CORRECTION,
                    'quantity' => 0, 'stock_before' => $physical, 'stock_after' => $physical,
                    'unit_cost' => $after['inventory_unit_cost'], 'total_cost' => $after['inventory_total_cost'],
                    'costing_method' => $method, 'reference_type' => 'instant_sale', 'reference_id' => $sale->id,
                    'reason' => $reason, 'created_by' => $actor->id,
                    'note' => 'تصحيح تكلفة بيع فقط. '.$adjustment->reference,
                ]);
                InstantSaleRevision::create([
                    'instant_sale_id' => $sale->id, 'actor_user_id' => $actor->id,
                    'action' => 'missing_cost_initialized', 'before_snapshot' => $before, 'after_snapshot' => $after,
                    'reason' => $reason, 'metadata' => ['inventory_adjustment_id' => $adjustment->id,
                        'missing_quantity' => $row['missing_quantity'], 'unit_cost' => $unitCost,
                        'currency' => $currency, 'stock_unchanged' => true],
                ]);
                $parentIds[] = $sale->parent_id ?: $sale->id;
            }
            $pendingInvoices = 0;
            if (Schema::hasTable('accounting_journal_entries')) {
                foreach (array_unique($parentIds) as $id) {
                    $invoice = InstantSale::query()->findOrFail($id);
                    $inspection = app(InventoryCostIntegrityService::class)->inspectInstantSale($invoice);
                    if ($inspection['ready']) {
                        // The normal projection respects cutover and closed periods.
                        // Failure aborts this entire correction, including its audit.
                        app(AccountingProjectionService::class)->syncOrFail($invoice);
                    } else {
                        $pendingInvoices++;
                        app(AccountingProjectionService::class)->sync($invoice);
                    }
                }
            }

            return ['corrected_count' => $selected->count(), 'adjustment_id' => $adjustment->id,
                'added_sale_cost' => round((float) $selected->sum('added_cost'), 6),
                'invoices_still_missing_cost' => $pendingInvoices, 'stock_unchanged' => true];
        }, 3);
    }

    private function buildPreview(Product $product, ?int $variantId, float $unitCost, string $currency, bool $lock = false): array
    {
        if (! is_finite($unitCost) || $unitCost <= 0) {
            $this->fail('أدخل تكلفة وحدة أكبر من صفر.');
        }
        $currency = $this->costing->normalizeCurrency($currency);
        $query = InstantSale::query()->with(['parentSale', 'paymentBox', 'parentSale.paymentBox'])
            ->where('product_id', $product->id)->where('quantity', '>', 0)
            ->when($variantId, fn ($q) => $q->where('size_color_id', $variantId), fn ($q) => $q->whereNull('size_color_id'))
            ->where(fn ($q) => $q->whereNull('inventory_total_cost')->orWhere(fn ($q) => $q->where('inventory_total_cost', 0)->whereNull('inventory_unit_cost')->whereNull('inventory_cost_method')))
            ->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }
        $sales = $query->get();
        $rows = [];
        foreach ($sales as $sale) {
            $root = $sale->parentSale ?? $sale;
            $blocked = null;
            if ($sale->isCancelled() || $root->isCancelled() || $sale->stock_restored || $root->stock_restored) {
                $blocked = 'البيع ملغى أو أُعيد مخزونه';
            } elseif ($sale->sales_order_id || $root->sales_order_id || $sale->maintenance_id || $root->maintenance_id) {
                $blocked = 'التكلفة مرتبطة بطلبية أو صيانة وتتطلب تصحيح مصدرها';
            } elseif (($sale->sale_kind ?? 'regular') === 'adjustment' || ($root->sale_kind ?? 'regular') === 'adjustment') {
                $blocked = 'فاتورة تسوية وليست بيع مخزون';
            } elseif (Schema::hasTable('sales_return_items') && DB::table('sales_return_items')->where('instant_sale_id', $sale->id)->exists()) {
                $blocked = 'البيع مرتبط بمرتجع ويتطلب مراجعة المرتجع أولًا';
            }
            $saleCurrency = $this->costing->normalizeCurrency($root->paymentBox?->currency ?? $sale->paymentBox?->currency ?? 'شيكل');
            if ($currency !== $saleCurrency) {
                $blocked = 'عملة التكلفة يجب أن تطابق عملة الفاتورة ('.$saleCurrency.')';
            }
            $allocations = $this->allocations($sale, $lock);
            $known = $allocations->whereNotNull('inventory_cost_layer_id');
            $knownQty = (float) $known->sum('quantity');
            $missing = round((float) $sale->quantity - $knownQty, 4);
            if ($missing <= self::EPSILON && $blocked === null) {
                continue;
            }
            if ((float) $allocations->sum('quantity') > (float) $sale->quantity + self::EPSILON
                || $allocations->contains(fn ($a) => $a->quantity <= 0)
                || $allocations->whereNull('inventory_cost_layer_id')->contains(fn ($a) => (float) $a->unit_cost !== 0.0)) {
                $blocked = 'توزيع التكلفة غير متسق أو يتضمن تكلفة تقديرية؛ يلزم فحصه منفصلًا';
            }
            foreach ($known as $allocation) {
                $layer = InventoryCostLayer::query()->find($allocation->inventory_cost_layer_id);
                if (! $layer || (int) $layer->product_id !== (int) $product->id
                    || (int) ($layer->size_color_id ?? 0) !== (int) ($variantId ?? 0)
                    || $this->costing->normalizeCurrency($layer->currency) !== $currency) {
                    $blocked = 'طبقة تكلفة غير مطابقة لهذا الصنف أو العملة';
                }
                if ((float) $allocation->unit_cost < 0
                    || abs((float) $allocation->total_cost - (float) $allocation->quantity * (float) $allocation->unit_cost) > 0.001) {
                    $blocked = 'توزيع التكلفة المعروف غير متسق ويحتاج مراجعة منفصلة';
                }
            }
            $movements = ProductStockMovement::query()->where('product_id', $product->id)
                ->where('reference_type', 'instant_sale')->where('reference_id', $sale->id)
                ->when($variantId, fn ($q) => $q->where('size_color_id', $variantId), fn ($q) => $q->whereNull('size_color_id'))
                ->where('quantity', '<', 0)->orderBy('id')->get();
            if ($movements->isNotEmpty() && abs(abs((float) $movements->sum('quantity')) - (float) $sale->quantity) > self::EPSILON) {
                $blocked = 'كمية حركة الصرف لا تطابق كمية البيع وتحتاج مراجعة منفصلة';
            }
            $knownCost = round((float) $known->sum('total_cost'), 6);
            $addedCost = round(max(0, $missing) * $unitCost, 6);
            $rows[] = ['id' => (int) $sale->id, 'invoice_id' => (int) $root->id,
                'invoice_number' => $root->serial_number ?: '#'.$root->id,
                'sold_at' => $sale->created_at?->toDateTimeString(),
                'quantity' => (float) $sale->quantity, 'missing_quantity' => max(0, $missing),
                'sale_unit_price' => $sale->cost !== null ? round((float) $sale->cost, 6) : null,
                'sale_line_total' => $sale->cost !== null ? round((float) $sale->cost * (float) $sale->quantity, 6) : null,
                'invoice_total' => $root->total_cost !== null ? round((float) $root->total_cost, 6) : null,
                'invoice_discount' => round((float) ($root->discount ?? 0), 6),
                'sale_price_included_in_package' => (bool) $root->offer_package_id && (float) $sale->cost === 0.0,
                'known_cost' => $knownCost, 'new_total_cost' => round($knownCost + $addedCost, 6),
                'added_cost' => $addedCost, 'profit_change' => -$addedCost,
                'currency' => $saleCurrency, 'eligible' => $blocked === null, 'blocked_reason' => $blocked,
                'fingerprint' => hash('sha256', json_encode([$sale->getRawOriginal(), $root->getRawOriginal(), $allocations->toArray(), $movements->toArray()])),
            ];
        }

        return ['product_id' => (int) $product->id, 'size_color_id' => $variantId,
            'product_name' => $product->nameAr, 'unit_cost' => $unitCost, 'currency' => $currency,
            'sales' => $rows, 'review_token' => hash('sha256', json_encode([$product->id, $variantId, $unitCost, $currency, $rows]))];
    }

    private function allocations(InstantSale $sale, bool $lock = false)
    {
        $q = InventoryCostAllocation::query()->where('reference_type', 'instant_sale')->where('reference_id', $sale->id)
            ->where('product_id', $sale->product_id)
            ->when($sale->size_color_id, fn ($q) => $q->where('size_color_id', $sale->size_color_id), fn ($q) => $q->whereNull('size_color_id'))
            ->orderBy('id');
        if ($lock) {
            $q->lockForUpdate();
        }

        return $q->get();
    }

    private function identity(Product $product, ?int $variantId): ?int
    {
        if (! $variantId) {
            if ($product->sizes()->whereHas('colorSizes')->exists()) {
                $this->fail('اختر اللون والمقاس أولًا.');
            }

            return null;
        }
        $variant = SizeColor::query()->with('size')->findOrFail($variantId);
        if ((int) $variant->size?->itemId !== (int) $product->id) {
            $this->fail('الصنف لا يتبع هذا المنتج.');
        }

        return (int) $variant->sizeId;
    }

    private function authorize(User $actor): void
    {
        if (! $actor->canAdjustInventoryCost()) {
            $this->fail('لا تملك صلاحية تعديل تكلفة المخزون.');
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['cost_correction' => [$message]]);
    }
}
