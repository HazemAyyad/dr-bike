<?php

namespace App\Services;

use App\Models\InventoryCostBalance;
use App\Models\InventoryCostLayer;
use App\Models\InventoryCostReview;
use App\Models\Product;
use App\Models\ProductStockMovement;
use App\Models\Size;
use App\Models\SizeColor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class LegacyInventoryAuditService
{
    private bool $previewMode = false;

    /** @var array<string, array{covered: float, opening_exists: bool}> */
    private array $previewLayers = [];

    /** @var array<string, object> */
    private array $previewPurchaseHistories = [];

    /** @var array<string, object> */
    private array $previewLegacyPurchases = [];

    public function __construct(
        private readonly InventoryCostingService $costing,
        private readonly ProductStockService $stock,
    ) {}

    /** @return array<string, bool> */
    public function schemaReadiness(): array
    {
        return collect([
            'inventory_cost_layers',
            'inventory_cost_balances',
            'inventory_cost_reviews',
            'product_stock_movements',
        ])->mapWithKeys(fn (string $table) => [$table => Schema::hasTable($table)])->all();
    }

    /**
     * @param  array<int, int|string>  $productIds
     * @return array{rows: array<int, array<string, mixed>>, created_layers: int, needs_review: int, schema: array<string, bool>}
     */
    public function run(bool $write = false, array $productIds = []): array
    {
        $schema = $this->schemaReadiness();
        if ($write && in_array(false, $schema, true)) {
            throw new \RuntimeException('Inventory accounting migrations must be applied before writing the legacy backfill.');
        }

        $ids = collect($productIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();
        $this->previewMode = ! $write;
        if ($this->previewMode) {
            $this->preparePreviewMaps($schema);
        }
        $rows = [];
        $created = 0;
        $review = 0;

        Product::query()
            ->when($ids->isNotEmpty(), fn ($query) => $query->whereIn('id', $ids))
            ->with('sizes.colorSizes')
            ->orderBy('id')
            ->chunkById(200, function ($products) use ($write, $schema, &$rows, &$created, &$review) {
                foreach ($products as $product) {
                    foreach ($this->identities($product) as [$physical, $variantId, $sizeId, $variantLabel]) {
                        if ($physical <= 0) {
                            continue;
                        }

                        $result = $this->auditIdentity(
                            $product,
                            $physical,
                            $variantId,
                            $sizeId,
                            $variantLabel,
                            $write,
                            $schema['inventory_cost_layers'],
                        );
                        $rows[] = $result;
                        $created += (int) $result['created'];
                        $review += (int) $result['needs_review'];
                    }
                }
            });

        return [
            'rows' => $rows,
            'created_layers' => $created,
            'needs_review' => $review,
            'schema' => $schema,
        ];
    }

    /**
     * Apply a small, restart-safe batch of identities which have a documented
     * legacy purchase cost. Physical stock is never changed by this operation.
     *
     * @return array{selected: int, created: int, skipped: int, failed: int, errors: array<int, string>}
     */
    public function applyReadyBatch(int $limit, string $operator): array
    {
        $limit = max(1, min(50, $limit));
        $preview = $this->run(false);
        $rows = collect($preview['rows'])
            ->where('status', 'ready')
            ->take($limit)
            ->values();
        $result = ['selected' => $rows->count(), 'created' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];

        foreach ($rows as $row) {
            try {
                $outcome = $this->createCoverageLayer(
                    productId: (int) $row['product_id'],
                    sizeColorId: $row['size_color_id'] ? (int) $row['size_color_id'] : null,
                    explicitCost: null,
                    explicitCurrency: null,
                    operator: $operator,
                    reason: 'اعتماد تكلفة الشراء التاريخية للمخزون القديم',
                    notes: null,
                );
                $result[$outcome === 'created' ? 'created' : 'skipped']++;
            } catch (\Throwable $exception) {
                $result['failed']++;
                $result['errors'][] = '#'.$row['product_id'].' '.($row['variant_label'] ?: '').': '.$exception->getMessage();
            }
        }

        return $result;
    }

    /**
     * Resolve one reviewed identity with an explicitly approved opening cost.
     */
    public function applyReviewedCost(
        string $identityKey,
        float $unitCost,
        string $currency,
        string $operator,
        string $reason,
        ?string $notes = null,
    ): string {
        if ($unitCost <= 0) {
            throw ValidationException::withMessages(['unit_cost' => ['تكلفة الوحدة يجب أن تكون أكبر من صفر.']]);
        }

        if (! preg_match('/^product:(\d+):variant:(main|\d+)$/', $identityKey, $matches)) {
            throw ValidationException::withMessages(['identity_key' => ['هوية المخزون غير صالحة.']]);
        }

        return $this->createCoverageLayer(
            productId: (int) $matches[1],
            sizeColorId: $matches[2] === 'main' ? null : (int) $matches[2],
            explicitCost: $unitCost,
            explicitCurrency: $currency,
            operator: $operator,
            reason: $reason,
            notes: $notes,
        );
    }

    /** @return array<int, array{int, int|null, int|null, string|null}> */
    private function identities(Product $product): array
    {
        if (! $this->stock->productHasVariants($product)) {
            return [[(int) $product->stock, null, null, null]];
        }

        $identities = [];
        foreach ($product->sizes as $size) {
            foreach ($size->colorSizes as $variant) {
                $identities[] = [
                    (int) $variant->stock,
                    (int) $variant->id,
                    (int) $size->id,
                    trim((string) $size->size.' / '.(string) ($variant->colorAr ?: $variant->colorEn)),
                ];
            }
        }

        return $identities;
    }

    /** @return array<string, mixed> */
    private function auditIdentity(
        Product $product,
        int $physical,
        ?int $variantId,
        ?int $sizeId,
        ?string $variantLabel,
        bool $write,
        bool $hasLayersTable,
    ): array {
        $covered = 0.0;
        $openingExists = false;
        if ($hasLayersTable) {
            if ($this->previewMode) {
                $layerSummary = $this->previewLayers[$this->identityMapKey((int) $product->id, $variantId)] ?? null;
                $covered = (float) ($layerSummary['covered'] ?? 0);
                $openingExists = (bool) ($layerSummary['opening_exists'] ?? false);
            } else {
                $layers = InventoryCostLayer::query()->where('product_id', $product->id)
                    ->when($variantId, fn ($query) => $query->where('size_color_id', $variantId), fn ($query) => $query->whereNull('size_color_id'));
                $covered = (float) (clone $layers)->sum('remaining_quantity');
                $openingExists = (clone $layers)->whereIn('source_type', ['opening_stock', 'opening_stock_backfill'])->exists();
            }
        }

        $missing = round(max(0, $physical - $covered), 4);
        $cost = $this->resolveOpeningCost($product, $variantId);
        $reason = null;
        $status = 'covered';

        if ($covered > $physical + 0.0001) {
            $reason = 'cost_quantity_exceeds_physical_stock';
            $status = 'over_covered';
        } elseif ($missing > 0.0001 && $openingExists) {
            $reason = 'existing_opening_layer_has_insufficient_coverage';
            $status = 'review';
        } elseif ($missing > 0.0001 && $cost['unit_cost'] === null) {
            $reason = 'reliable_opening_unit_cost_not_found';
            $status = 'review';
        } elseif ($missing > 0.0001) {
            $status = 'ready';
        }

        if ($write && $reason !== null) {
            $this->storePendingReview(
                productId: (int) $product->id,
                sizeId: $sizeId,
                sizeColorId: $variantId,
                physical: $physical,
                covered: $covered,
                missing: $missing,
                reason: $reason,
                evidence: ['cost_source' => $cost['source'], 'cost_source_id' => $cost['source_id'] ?? null],
            );
        }

        $wasCreated = false;
        if ($write && $missing > 0.0001 && $reason === null) {
            $wasCreated = $this->createCoverageLayer(
                productId: (int) $product->id,
                sizeColorId: $variantId,
                explicitCost: null,
                explicitCurrency: null,
                operator: 'artisan',
                reason: 'اعتماد تكلفة الشراء التاريخية للمخزون القديم',
                notes: null,
            ) === 'created';
        }

        $referenceCost = $variantId ? $this->resolveProductLevelReferenceCost($product) : null;

        return [
            'identity_key' => $this->costing->identityKey((int) $product->id, $variantId),
            'product_id' => (int) $product->id,
            'product_code' => (string) ($product->product_code ?? ''),
            'product_name' => (string) ($product->nameAr ?: $product->nameEng ?: ('#'.$product->id)),
            'size_id' => $sizeId,
            'size_color_id' => $variantId,
            'variant_label' => $variantLabel,
            'physical_quantity' => $physical,
            'costed_quantity' => round($covered, 4),
            'missing_quantity' => $missing,
            'suggested_unit_cost' => $cost['unit_cost'],
            'suggested_currency' => $cost['currency'],
            'suggested_value' => $cost['unit_cost'] === null ? null : round($missing * (float) $cost['unit_cost'], 6),
            'cost_source' => $cost['source'],
            'cost_source_id' => $cost['source_id'] ?? null,
            'reference_unit_cost' => $referenceCost['unit_cost'] ?? null,
            'reference_currency' => $referenceCost['currency'] ?? null,
            'reference_source' => $referenceCost['source'] ?? null,
            'reference_source_id' => $referenceCost['source_id'] ?? null,
            'status' => $status,
            'reason' => $reason,
            'created' => $wasCreated,
            'needs_review' => $status === 'review' || $status === 'over_covered',
        ];
    }

    private function createCoverageLayer(
        int $productId,
        ?int $sizeColorId,
        ?float $explicitCost,
        ?string $explicitCurrency,
        string $operator,
        string $reason,
        ?string $notes,
    ): string {
        $schema = $this->schemaReadiness();
        if (in_array(false, $schema, true)) {
            throw new \RuntimeException('Inventory accounting migrations must be applied before writing the legacy backfill.');
        }

        $operator = trim($operator);
        if ($operator === '') {
            throw ValidationException::withMessages(['operator' => ['اسم منفذ المراجعة مطلوب.']]);
        }

        return DB::transaction(function () use ($productId, $sizeColorId, $explicitCost, $explicitCurrency, $operator, $reason, $notes) {
            $product = Product::query()->lockForUpdate()->findOrFail($productId);
            $sizeId = null;
            if ($sizeColorId !== null) {
                $variant = SizeColor::query()->lockForUpdate()->findOrFail($sizeColorId);
                $size = Size::query()->lockForUpdate()->findOrFail($variant->sizeId);
                if ((int) $size->itemId !== (int) $product->id) {
                    throw ValidationException::withMessages(['identity_key' => ['المتغير لا يتبع المنتج المحدد.']]);
                }
                $physical = (float) $variant->stock;
                $sizeId = (int) $variant->sizeId;
            } else {
                if (Size::query()->where('itemId', $product->id)->whereHas('colorSizes')->exists()) {
                    throw ValidationException::withMessages(['identity_key' => ['يجب معالجة هذا المنتج على مستوى الحجم واللون.']]);
                }
                $physical = (float) $product->stock;
            }

            $identityKey = $this->costing->identityKey((int) $product->id, $sizeColorId);
            $idempotencyKey = 'legacy-opening:'.$identityKey;
            $layers = InventoryCostLayer::query()
                ->where('product_id', $product->id)
                ->when($sizeColorId !== null, fn ($query) => $query->where('size_color_id', $sizeColorId), fn ($query) => $query->whereNull('size_color_id'))
                ->lockForUpdate()
                ->get();

            if ($layers->contains(fn (InventoryCostLayer $layer) => $layer->idempotency_key === $idempotencyKey)) {
                return 'already_applied';
            }

            $covered = (float) $layers->sum('remaining_quantity');
            $missing = round(max(0, $physical - $covered), 4);
            $openingExists = $layers->contains(fn (InventoryCostLayer $layer) => in_array($layer->source_type, ['opening_stock', 'opening_stock_backfill'], true));

            if ($covered > $physical + 0.0001) {
                $this->storePendingReview($productId, $sizeId, $sizeColorId, $physical, $covered, 0, 'cost_quantity_exceeds_physical_stock');

                return 'over_covered';
            }
            if ($missing <= 0.0001) {
                $this->syncBalance(
                    $product,
                    $sizeId,
                    $sizeColorId,
                    $layers->filter(fn (InventoryCostLayer $layer) => (float) $layer->remaining_quantity > 0),
                );

                return 'covered';
            }

            $cost = $explicitCost !== null
                ? ['unit_cost' => $explicitCost, 'currency' => $explicitCurrency ?: 'شيكل', 'source' => 'administrative_manual_cost', 'source_id' => null]
                : $this->resolveOpeningCost($product, $sizeColorId, true);

            if ($cost['unit_cost'] === null || (float) $cost['unit_cost'] <= 0) {
                $this->storePendingReview($productId, $sizeId, $sizeColorId, $physical, $covered, $missing, 'reliable_opening_unit_cost_not_found');

                return 'review_required';
            }
            if ($openingExists && $explicitCost === null) {
                $this->storePendingReview($productId, $sizeId, $sizeColorId, $physical, $covered, $missing, 'existing_opening_layer_has_insufficient_coverage');

                return 'review_required';
            }

            $currency = $this->costing->normalizeCurrency($cost['currency']);
            $this->costing->assertCurrencyCompatible($product, $currency);
            $earliestExistingLayer = $layers
                ->filter(fn (InventoryCostLayer $layer) => $layer->effective_at !== null)
                ->sortBy('effective_at')
                ->first();
            $effectiveAt = collect([
                $product->created_at,
                $earliestExistingLayer?->effective_at?->copy()->subSecond(),
            ])->filter()->sort()->first() ?? now();

            $layer = InventoryCostLayer::query()->create([
                'product_id' => $product->id,
                'size_id' => $sizeId,
                'size_color_id' => $sizeColorId,
                'quantity' => $missing,
                'remaining_quantity' => $missing,
                'unit_cost' => $cost['unit_cost'],
                'original_unit_cost' => $cost['unit_cost'],
                'currency' => $currency,
                'source_type' => 'opening_stock_backfill',
                'source_id' => null,
                'idempotency_key' => $idempotencyKey,
                'effective_at' => $effectiveAt,
            ]);

            $allLayers = InventoryCostLayer::query()
                ->where('product_id', $product->id)
                ->when($sizeColorId !== null, fn ($query) => $query->where('size_color_id', $sizeColorId), fn ($query) => $query->whereNull('size_color_id'))
                ->where('remaining_quantity', '>', 0)
                ->lockForUpdate()
                ->get();
            $this->syncBalance($product, $sizeId, $sizeColorId, $allLayers, $currency);

            $auditNote = 'تغطية محاسبية لمخزون قديم دون تغيير الكمية الفعلية. المنفذ: '.$operator.'. السبب: '.trim($reason);
            if (filled($notes)) {
                $auditNote .= '. ملاحظات: '.trim((string) $notes);
            }
            $this->stock->logMovement(
                productId: (int) $product->id,
                sizeId: $sizeId,
                sizeColorId: $sizeColorId,
                type: ProductStockMovement::TYPE_OPENING_STOCK,
                quantity: 0,
                stockBefore: (int) $physical,
                stockAfter: (int) $physical,
                referenceType: 'inventory_backfill',
                referenceId: (int) $layer->id,
                note: $auditNote,
                userId: null,
                unitCost: (float) $cost['unit_cost'],
                totalCost: round($missing * (float) $cost['unit_cost'], 6),
                costingMethod: $this->costing->currentMethod(),
                reason: 'legacy_opening_cost_backfill',
            );

            InventoryCostReview::query()->updateOrCreate(
                ['identity_key' => $identityKey],
                [
                    'product_id' => $product->id,
                    'size_id' => $sizeId,
                    'size_color_id' => $sizeColorId,
                    'physical_quantity' => $physical,
                    'costed_quantity' => $physical,
                    'missing_quantity' => 0,
                    'status' => 'resolved',
                    'reason' => 'legacy_opening_cost_backfill',
                    'evidence' => [
                        'cost_source' => $cost['source'],
                        'cost_source_id' => $cost['source_id'] ?? null,
                        'approved_unit_cost' => (float) $cost['unit_cost'],
                        'currency' => $currency,
                        'operator' => $operator,
                        'reason' => trim($reason),
                        'notes' => filled($notes) ? trim((string) $notes) : null,
                        'layer_id' => (int) $layer->id,
                    ],
                    'resolved_by' => null,
                    'resolved_at' => now(),
                ]
            );

            return 'created';
        }, 3);
    }

    /** @param array<string, mixed> $evidence */
    private function storePendingReview(
        int $productId,
        ?int $sizeId,
        ?int $sizeColorId,
        float $physical,
        float $covered,
        float $missing,
        string $reason,
        array $evidence = [],
    ): void {
        InventoryCostReview::query()->updateOrCreate(
            ['identity_key' => $this->costing->identityKey($productId, $sizeColorId)],
            [
                'product_id' => $productId,
                'size_id' => $sizeId,
                'size_color_id' => $sizeColorId,
                'physical_quantity' => $physical,
                'costed_quantity' => $covered,
                'missing_quantity' => $missing,
                'status' => 'pending',
                'reason' => $reason,
                'evidence' => $evidence,
                'resolved_by' => null,
                'resolved_at' => null,
            ]
        );
    }

    /** @param \Illuminate\Support\Collection<int, InventoryCostLayer> $layers */
    private function syncBalance(
        Product $product,
        ?int $sizeId,
        ?int $sizeColorId,
        $layers,
        ?string $currency = null,
    ): void {
        $quantity = (float) $layers->sum('remaining_quantity');
        $value = (float) $layers->sum(fn (InventoryCostLayer $layer) => (float) $layer->remaining_quantity * (float) $layer->unit_cost);
        $currency = $this->costing->normalizeCurrency($currency ?: ($layers->first()?->currency ?? 'شيكل'));
        $attributes = ['identity_key' => $this->costing->identityKey((int) $product->id, $sizeColorId)];
        $balance = InventoryCostBalance::query()->where($attributes)->lockForUpdate()->first();
        $values = [
            'product_id' => $product->id,
            'size_id' => $sizeId,
            'size_color_id' => $sizeColorId,
            'quantity' => $quantity,
            'inventory_value' => round($value, 6),
            'moving_average_unit_cost' => $quantity > 0 ? $value / $quantity : 0,
            'currency' => $currency,
            'needs_review' => false,
            'review_reason' => null,
        ];
        $balance ? $balance->update($values) : InventoryCostBalance::query()->create($attributes + $values);
    }

    /** @return array{unit_cost: float|null, source: string, source_id: int|null, currency: string} */
    private function resolveOpeningCost(Product $product, ?int $variantId, bool $forceDatabase = false): array
    {
        if ($this->previewMode && ! $forceDatabase) {
            $key = $this->identityMapKey((int) $product->id, $variantId);
            $history = $this->previewPurchaseHistories[$key] ?? null;
            $price = (float) ($history?->unit_price ?? 0);
            if ($price > 0) {
                return [
                    'unit_cost' => $price,
                    'source' => 'purchase_price_histories.latest',
                    'source_id' => (int) $history->id,
                    'currency' => $this->costing->normalizeCurrency($history?->currency ?? 'NIS'),
                ];
            }

            $legacy = $this->previewLegacyPurchases[$key] ?? null;
            $price = (float) ($legacy?->price ?? 0);
            if ($price > 0) {
                return [
                    'unit_cost' => $price,
                    'source' => 'purchase_products.latest_legacy_purchase_cost',
                    'source_id' => (int) $legacy->id,
                    'currency' => 'شيكل',
                ];
            }

            return ['unit_cost' => null, 'source' => 'admin_review_required', 'source_id' => null, 'currency' => 'شيكل'];
        }

        if (Schema::hasTable('purchase_price_histories')) {
            $query = DB::table('purchase_price_histories')
                ->where('purchase_price_histories.product_id', $product->id)
                ->whereNotNull('purchase_price_histories.purchase_receipt_item_id');
            if (Schema::hasColumn('purchase_price_histories', 'size_color_id')) {
                $query->when($variantId, fn ($row) => $row->where('size_color_id', $variantId), fn ($row) => $row->whereNull('size_color_id'));
            } elseif ($variantId !== null && Schema::hasTable('purchase_receipt_items')) {
                $query->join('purchase_receipt_items', 'purchase_receipt_items.id', '=', 'purchase_price_histories.purchase_receipt_item_id')
                    ->where('purchase_receipt_items.size_color_id', $variantId)
                    ->select('purchase_price_histories.*');
            } elseif ($variantId !== null) {
                $query->whereRaw('1 = 0');
            }
            $history = $query->orderByDesc('purchase_price_histories.id')->first();
            $price = (float) ($history?->unit_price ?? 0);
            if ($price > 0) {
                return [
                    'unit_cost' => $price,
                    'source' => 'purchase_price_histories.latest',
                    'source_id' => (int) $history->id,
                    'currency' => $this->costing->normalizeCurrency($history?->currency ?? 'NIS'),
                ];
            }
        }

        if (Schema::hasTable('purchase_products')) {
            $query = DB::table('purchase_products')->where('product_id', $product->id);
            if ($variantId !== null) {
                if (! Schema::hasColumn('purchase_products', 'size_color_id')) {
                    return ['unit_cost' => null, 'source' => 'admin_review_required', 'source_id' => null, 'currency' => 'شيكل'];
                }
                $query->where('size_color_id', $variantId);
            }
            $legacy = $query->orderByDesc('id')->first();
            $price = (float) ($legacy?->price ?? 0);
            if ($price > 0) {
                return [
                    'unit_cost' => $price,
                    'source' => 'purchase_products.latest_legacy_purchase_cost',
                    'source_id' => (int) $legacy->id,
                    'currency' => 'شيكل',
                ];
            }
        }

        return ['unit_cost' => null, 'source' => 'admin_review_required', 'source_id' => null, 'currency' => 'شيكل'];
    }

    /** @return array{unit_cost: float, source: string, source_id: int, currency: string}|null */
    private function resolveProductLevelReferenceCost(Product $product): ?array
    {
        if ($this->previewMode) {
            $legacy = $this->previewLegacyPurchases[$this->identityMapKey((int) $product->id, null)] ?? null;
        } elseif (Schema::hasTable('purchase_products')) {
            $legacy = DB::table('purchase_products')
                ->where('product_id', $product->id)
                ->orderByDesc('id')
                ->first();
        } else {
            $legacy = null;
        }

        $price = (float) ($legacy?->price ?? 0);

        return $price > 0 ? [
            'unit_cost' => $price,
            'source' => 'purchase_products.product_level_reference',
            'source_id' => (int) $legacy->id,
            'currency' => 'شيكل',
        ] : null;
    }

    /** @param array<string, bool> $schema */
    private function preparePreviewMaps(array $schema): void
    {
        $this->previewLayers = [];
        $this->previewPurchaseHistories = [];
        $this->previewLegacyPurchases = [];

        if ($schema['inventory_cost_layers']) {
            DB::table('inventory_cost_layers')
                ->select(['product_id', 'size_color_id'])
                ->selectRaw('COALESCE(SUM(remaining_quantity), 0) AS covered')
                ->selectRaw("MAX(CASE WHEN source_type IN ('opening_stock', 'opening_stock_backfill') THEN 1 ELSE 0 END) AS opening_exists")
                ->groupBy('product_id', 'size_color_id')
                ->get()
                ->each(function ($row) {
                    $this->previewLayers[$this->identityMapKey((int) $row->product_id, $row->size_color_id ? (int) $row->size_color_id : null)] = [
                        'covered' => (float) $row->covered,
                        'opening_exists' => (bool) $row->opening_exists,
                    ];
                });
        }

        if (Schema::hasTable('purchase_price_histories')) {
            $variantAware = Schema::hasColumn('purchase_price_histories', 'size_color_id');
            $historyQuery = DB::table('purchase_price_histories')
                ->whereNotNull('purchase_price_histories.purchase_receipt_item_id')
                ->orderByDesc('purchase_price_histories.id');
            $receiptVariantAware = ! $variantAware && Schema::hasTable('purchase_receipt_items');
            if ($receiptVariantAware) {
                $historyQuery->leftJoin('purchase_receipt_items', 'purchase_receipt_items.id', '=', 'purchase_price_histories.purchase_receipt_item_id')
                    ->select('purchase_price_histories.*', 'purchase_receipt_items.size_color_id as receipt_size_color_id');
            }
            $historyQuery->get()->each(function ($row) use ($variantAware, $receiptVariantAware) {
                $variantId = $variantAware && $row->size_color_id
                    ? (int) $row->size_color_id
                    : ($receiptVariantAware && $row->receipt_size_color_id ? (int) $row->receipt_size_color_id : null);
                $key = $this->identityMapKey((int) $row->product_id, $variantId);
                $this->previewPurchaseHistories[$key] ??= $row;
            });
        }

        if (Schema::hasTable('purchase_products')) {
            $variantAware = Schema::hasColumn('purchase_products', 'size_color_id');
            DB::table('purchase_products')->orderByDesc('id')->get()->each(function ($row) use ($variantAware) {
                $variantId = $variantAware && $row->size_color_id ? (int) $row->size_color_id : null;
                $key = $this->identityMapKey((int) $row->product_id, $variantId);
                $this->previewLegacyPurchases[$key] ??= $row;
            });
        }
    }

    private function identityMapKey(int $productId, ?int $variantId): string
    {
        return $productId.':'.($variantId ?: 'main');
    }
}
