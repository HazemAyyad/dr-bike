<?php

namespace App\Console\Commands;

use App\Models\InventoryCostLayer;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class BackfillInventoryCostLayers extends Command
{
    protected $signature = 'inventory:backfill-cost-layers {--write : Create missing opening layers}';

    protected $description = 'Create idempotent opening inventory cost layers for current stock.';

    public function handle(): int
    {
        if (! Schema::hasTable('inventory_cost_layers')) {
            $this->error('inventory_cost_layers table does not exist.');

            return self::FAILURE;
        }

        $write = (bool) $this->option('write');
        $rows = [];
        $created = 0;
        $review = 0;

        Product::query()
            ->where('stock', '>', 0)
            ->orderBy('id')
            ->chunkById(200, function ($products) use ($write, &$rows, &$created, &$review) {
                foreach ($products as $product) {
                    $stock = (float) $product->stock;
                    $covered = (float) InventoryCostLayer::query()
                        ->where('product_id', $product->id)
                        ->sum('remaining_quantity');
                    $quantity = round(max(0, $stock - $covered), 4);
                    if ($quantity <= 0.0001) {
                        continue;
                    }

                    $cost = $this->resolveOpeningCost($product);
                    if ($cost['unit_cost'] <= 0) {
                        $review++;
                    }

                    if ($write && ! InventoryCostLayer::query()
                        ->where('product_id', $product->id)
                        ->where('source_type', 'opening_stock_backfill')
                        ->exists()
                    ) {
                        InventoryCostLayer::create([
                            'product_id' => $product->id,
                            'quantity' => $quantity,
                            'remaining_quantity' => $quantity,
                            'unit_cost' => $cost['unit_cost'],
                            'currency' => 'شيكل',
                            'source_type' => 'opening_stock_backfill',
                            'source_id' => null,
                            'effective_at' => now(),
                        ]);
                        $created++;
                    }

                    $rows[] = [
                        'product_id' => (int) $product->id,
                        'product' => (string) ($product->nameAr ?? $product->nameEng ?? ''),
                        'opening_quantity' => $quantity,
                        'opening_unit_cost' => $cost['unit_cost'],
                        'source' => $cost['source'],
                        'needs_review' => $cost['unit_cost'] <= 0,
                    ];
                }
            });

        $path = 'inventory-cost-backfill/backfill-'.now()->format('Ymd-His').'.json';
        Storage::disk('local')->put($path, json_encode([
            'mode' => $write ? 'write' : 'dry-run',
            'created_layers' => $created,
            'needs_review' => $review,
            'rows' => $rows,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        $this->info(($write ? 'Created' : 'Would create').' opening layers: '.$created);
        $this->info('Products needing cost review: '.$review);
        $this->info('Report: storage/app/'.$path);

        return self::SUCCESS;
    }

    /**
     * @return array{unit_cost: float, source: string}
     */
    private function resolveOpeningCost(Product $product): array
    {
        if (Schema::hasTable('purchase_price_histories')) {
            $price = (float) DB::table('purchase_price_histories')
                ->where('product_id', $product->id)
                ->orderByDesc('priced_at')
                ->orderByDesc('id')
                ->value('unit_price');
            if ($price > 0) {
                return ['unit_cost' => $price, 'source' => 'purchase_price_histories.latest'];
            }
        }

        if (Schema::hasTable('purchase_products')) {
            $price = (float) DB::table('purchase_products')
                ->where('product_id', $product->id)
                ->orderByDesc('id')
                ->value('price');
            if ($price > 0) {
                return ['unit_cost' => $price, 'source' => 'purchase_products.latest_cache'];
            }
        }

        foreach (['purchase_price', 'cost_price', 'price'] as $column) {
            if (Schema::hasColumn('products', $column)) {
                $price = (float) ($product->{$column} ?? 0);
                if ($price > 0) {
                    return ['unit_cost' => $price, 'source' => 'products.'.$column];
                }
            }
        }

        return ['unit_cost' => 0.0, 'source' => 'admin_review_required'];
    }
}
