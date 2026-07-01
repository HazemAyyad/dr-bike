<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\SizeColor;
use App\Services\Meta\MetaCatalogService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncMetaCatalogProductJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(public int $productId, public ?int $variantId = null)
    {
        $this->afterCommit();
    }

    public function handle(MetaCatalogService $service): void
    {
        $product = Product::query()->with('sizes.colorSizes')->find($this->productId);
        if (! $product) return;
        if ($this->variantId === null) {
            $variants = $product->sizes->flatMap->colorSizes;
            if ($variants->isNotEmpty()) {
                foreach ($variants as $variant) {
                    self::dispatch((int) $product->id, (int) $variant->id);
                }
                return;
            }
        }
        $variant = $this->variantId ? SizeColor::query()->find($this->variantId) : null;
        $service->syncProduct($product, $variant);
    }
}
