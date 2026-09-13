<?php

namespace App\Services;

use App\Models\InventoryCostBalance;
use App\Models\InventoryCostLayer;
use App\Models\InventoryCostReview;
use App\Models\Product;
use App\Models\ProductStockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
            InventoryCostReview::query()->updateOrCreate(
                ['identity_key' => $this->costing->identityKey((int) $product->id, $variantId)],
                [
                    'product_id' => $product->id,
                    'size_id' => $sizeId,
                    'size_color_id' => $variantId,
                    'physical_quantity' => $physical,
                    'costed_quantity' => $covered,
                    'missing_quantity' => $missing,
                    'status' => 'pending',
                    'reason' => $reason,
                    'evidence' => ['cost_source' => $cost['source']],
                ]
            );
        }

        $wasCreated = false;
        if ($write && $missing > 0.0001 && $reason === null) {
            DB::transaction(function () use ($product, $physical, $missing, $variantId, $sizeId, $cost, &$wasCreated) {
                $key = 'legacy-opening:'.$this->costing->identityKey((int) $product->id, $variantId);
                if (InventoryCostLayer::query()->where('idempotency_key', $key)->lockForUpdate()->exists()) {
                    return;
                }

                $currency = $this->costing->normalizeCurrency($cost['currency']);
                $layer = InventoryCostLayer::query()->create([
                    'product_id' => $product->id,
                    'size_id' => $sizeId,
                    'size_color_id' => $variantId,
                    'quantity' => $missing,
                    'remaining_quantity' => $missing,
                    'unit_cost' => $cost['unit_cost'],
                    'original_unit_cost' => $cost['unit_cost'],
                    'currency' => $currency,
                    'source_type' => 'opening_stock_backfill',
                    'source_id' => null,
                    'idempotency_key' => $key,
                    'effective_at' => now(),
                ]);

                $allLayers = InventoryCostLayer::query()->where('product_id', $product->id)
                    ->when($variantId, fn ($query) => $query->where('size_color_id', $variantId), fn ($query) => $query->whereNull('size_color_id'))
                    ->where('remaining_quantity', '>', 0);
                $quantity = (float) (clone $allLayers)->sum('remaining_quantity');
                $value = (float) (clone $allLayers)->selectRaw('COALESCE(SUM(remaining_quantity * unit_cost), 0) AS value')->value('value');

                InventoryCostBalance::query()->updateOrCreate(
                    ['identity_key' => $this->costing->identityKey((int) $product->id, $variantId)],
                    [
                        'product_id' => $product->id,
                        'size_id' => $sizeId,
                        'size_color_id' => $variantId,
                        'quantity' => $quantity,
                        'inventory_value' => $value,
                        'moving_average_unit_cost' => $quantity > 0 ? $value / $quantity : 0,
                        'currency' => $currency,
                        'needs_review' => false,
                        'review_reason' => null,
                    ]
                );

                $this->stock->logMovement(
                    productId: (int) $product->id,
                    sizeId: $sizeId,
                    sizeColorId: $variantId,
                    type: ProductStockMovement::TYPE_OPENING_STOCK,
                    quantity: 0,
                    stockBefore: $physical,
                    stockAfter: $physical,
                    referenceType: 'inventory_backfill',
                    referenceId: (int) $layer->id,
                    note: 'تغطية محاسبية لمخزون قديم دون تغيير الكمية الفعلية',
                    userId: null,
                    unitCost: (float) $cost['unit_cost'],
                    totalCost: round($missing * (float) $cost['unit_cost'], 6),
                    costingMethod: $this->costing->currentMethod(),
                    reason: 'legacy_opening_cost_backfill',
                );
                $wasCreated = true;
            });
        }

        return [
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
            'status' => $status,
            'reason' => $reason,
            'created' => $wasCreated,
            'needs_review' => $status === 'review' || $status === 'over_covered',
        ];
    }

    /** @return array{unit_cost: float|null, source: string, currency: string} */
    private function resolveOpeningCost(Product $product, ?int $variantId): array
    {
        if ($this->previewMode) {
            $key = $this->identityMapKey((int) $product->id, $variantId);
            $history = $this->previewPurchaseHistories[$key] ?? null;
            $price = (float) ($history?->unit_price ?? 0);
            if ($price > 0) {
                return [
                    'unit_cost' => $price,
                    'source' => 'purchase_price_histories.latest',
                    'currency' => $this->costing->normalizeCurrency($history?->currency ?? 'NIS'),
                ];
            }

            $legacy = $this->previewLegacyPurchases[$key] ?? null;
            $price = (float) ($legacy?->price ?? 0);
            if ($price > 0) {
                return [
                    'unit_cost' => $price,
                    'source' => 'purchase_products.latest_legacy_purchase_cost',
                    'currency' => $this->costing->normalizeCurrency($legacy?->currency ?? 'NIS'),
                ];
            }

            return ['unit_cost' => null, 'source' => 'admin_review_required', 'currency' => 'شيكل'];
        }

        if (Schema::hasTable('purchase_price_histories')) {
            $query = DB::table('purchase_price_histories')->where('product_id', $product->id);
            if (Schema::hasColumn('purchase_price_histories', 'size_color_id')) {
                $query->when($variantId, fn ($row) => $row->where('size_color_id', $variantId), fn ($row) => $row->whereNull('size_color_id'));
            }
            $history = $query->orderByDesc('id')->first();
            $price = (float) ($history?->unit_price ?? 0);
            if ($price > 0) {
                return [
                    'unit_cost' => $price,
                    'source' => 'purchase_price_histories.latest',
                    'currency' => $this->costing->normalizeCurrency($history?->currency ?? 'NIS'),
                ];
            }
        }

        if (Schema::hasTable('purchase_products')) {
            $query = DB::table('purchase_products')->where('product_id', $product->id);
            if ($variantId !== null) {
                if (! Schema::hasColumn('purchase_products', 'size_color_id')) {
                    return ['unit_cost' => null, 'source' => 'admin_review_required', 'currency' => 'شيكل'];
                }
                $query->where('size_color_id', $variantId);
            }
            $legacy = $query->orderByDesc('id')->first();
            $price = (float) ($legacy?->price ?? 0);
            if ($price > 0) {
                return [
                    'unit_cost' => $price,
                    'source' => 'purchase_products.latest_legacy_purchase_cost',
                    'currency' => $this->costing->normalizeCurrency($legacy?->currency ?? 'NIS'),
                ];
            }
        }

        return ['unit_cost' => null, 'source' => 'admin_review_required', 'currency' => 'شيكل'];
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
            DB::table('purchase_price_histories')->orderByDesc('id')->get()->each(function ($row) use ($variantAware) {
                $variantId = $variantAware && $row->size_color_id ? (int) $row->size_color_id : null;
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
