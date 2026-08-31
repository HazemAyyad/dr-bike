<?php

namespace App\Jobs;

use App\Exceptions\MetaCatalogValidationException;
use App\Models\Product;
use App\Models\SizeColor;
use App\Models\WhatsAppAccount;
use App\Services\Meta\MetaCatalogService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncMetaCatalogProductJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(
        public int $productId,
        public ?int $variantId = null,
        public ?int $whatsappAccountId = null,
    )
    {
        $this->afterCommit();
    }

    public function handle(MetaCatalogService $service): void
    {
        $product = Product::query()->with('sizes.colorSizes')->find($this->productId);
        if (! $product) return;
        $account = $this->whatsappAccountId
            ? WhatsAppAccount::query()->find($this->whatsappAccountId)
            : null;
        $service = $account ? $service->forAccount($account) : $service;
        if ($this->variantId === null) {
            $variants = $product->sizes->flatMap->colorSizes;
            if ($variants->isNotEmpty()) {
                foreach ($variants as $variant) {
                    try {
                        self::dispatch((int) $product->id, (int) $variant->id, $this->whatsappAccountId);
                    } catch (\Throwable) {
                        // Keep the remaining variants moving when the queue
                        // driver executes jobs synchronously.
                    }
                }
                return;
            }
        }
        $variant = $this->variantId ? SizeColor::query()->find($this->variantId) : null;
        try {
            $service->syncProduct($product, $variant);
        } catch (MetaCatalogValidationException $e) {
            Log::warning('[MetaCatalogProduct] skipped invalid item', [
                'product_id' => $product->id,
                'variant_id' => $variant?->id,
                'reason' => $e->getMessage(),
            ]);
            // Local eligibility errors are permanent until the product is
            // corrected. The service already marked the item as failed.
        } catch (\Throwable $e) {
            Log::error('[MetaCatalogProduct] sync failed without blocking inventory operation', [
                'product_id' => $product->id,
                'variant_id' => $variant?->id,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            // Stock changes can dispatch this job after a sale transaction.
            // With the sync queue driver, rethrowing an external Meta error
            // would make a successfully persisted sale appear to have failed.
            // MetaCatalogService already records the failed sync for retry.
        }
    }
}
