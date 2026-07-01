<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\SizeColor;
use App\Services\Meta\MetaCatalogService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncMetaCatalogProductJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

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
                    try {
                        self::dispatch((int) $product->id, (int) $variant->id);
                    } catch (\Throwable) {
                        // Keep the remaining variants moving when the queue
                        // driver executes jobs synchronously.
                    }
                }
                return;
            }
        }
        $variant = $this->variantId ? SizeColor::query()->find($this->variantId) : null;
        $service->syncProduct($product, $variant);
    }
}
