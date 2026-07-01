<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\MetaCatalogSyncLog;
use App\Models\SizeColor;
use App\Services\Meta\MetaCatalogService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DeleteMetaCatalogItemJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public int $productId,
        public ?int $variantId = null,
        public ?string $capturedMetaItemId = null,
        public ?string $capturedRetailerId = null,
    )
    {
        $this->afterCommit();
    }

    public function handle(MetaCatalogService $service): void
    {
        $product = Product::withTrashed()->find($this->productId);
        $variant = $this->variantId ? SizeColor::query()->find($this->variantId) : null;
        if ($this->variantId && ! $variant && $this->capturedMetaItemId) {
            try {
                $response = $service->deleteCatalogItem($this->capturedMetaItemId);
                MetaCatalogSyncLog::query()->create([
                    'product_id' => $product?->id ?: $this->productId,
                    'variant_id' => $this->variantId,
                    'action' => 'delete',
                    'status' => 'success',
                    'meta_catalog_item_id' => $this->capturedMetaItemId,
                    'retailer_id' => $this->capturedRetailerId,
                    'response_payload' => $response,
                ]);
            } catch (\Throwable $e) {
                MetaCatalogSyncLog::query()->create([
                    'product_id' => $product?->id ?: $this->productId,
                    'variant_id' => $this->variantId,
                    'action' => 'delete',
                    'status' => 'failed',
                    'meta_catalog_item_id' => $this->capturedMetaItemId,
                    'retailer_id' => $this->capturedRetailerId,
                    'error_message' => $e->getMessage(),
                ]);
                throw $e;
            }
            return;
        }
        if (! $product) return;
        $service->disable($product, $variant);
    }
}
