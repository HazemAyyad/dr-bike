<?php

namespace App\Jobs;

use App\Models\MetaCatalogSyncLog;
use App\Models\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class BulkSyncMetaCatalogJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function handle(): void
    {
        $synced = 0;
        $failed = 0;
        Log::info('[MetaCatalogBulk] started');
        Product::query()
            ->where('isShow', true)
            ->with('sizes.colorSizes')
            ->chunkById(100, function ($products) use (&$synced, &$failed) {
                foreach ($products as $product) {
                    $variants = $product->sizes->flatMap->colorSizes;
                    if ($variants->isEmpty()) {
                        try {
                            SyncMetaCatalogProductJob::dispatch((int) $product->id)
                                ->onConnection('database');
                            $synced++;
                        } catch (\Throwable $e) {
                            $failed++;
                            Log::error('[MetaCatalogBulk] product queue failed', [
                                'product_id' => $product->id,
                                'error' => $e->getMessage(),
                            ]);
                            // The sync job already stores its failed status/error.
                            // A single invalid product must not abort the bulk run,
                            // especially when QUEUE_CONNECTION=sync.
                        }
                        continue;
                    }
                    foreach ($variants as $variant) {
                        try {
                            SyncMetaCatalogProductJob::dispatch((int) $product->id, (int) $variant->id)
                                ->onConnection('database');
                            $synced++;
                        } catch (\Throwable $e) {
                            $failed++;
                            Log::error('[MetaCatalogBulk] variant queue failed', [
                                'product_id' => $product->id,
                                'variant_id' => $variant->id,
                                'error' => $e->getMessage(),
                            ]);
                            // Continue syncing the remaining variants/products.
                        }
                    }
                }
            });

        MetaCatalogSyncLog::query()->create([
            'action' => 'bulk_sync',
            'status' => 'success',
            'response_payload' => [
                'queued_at' => now()->toIso8601String(),
                'queued_items' => $synced,
                'failed_to_queue' => $failed,
            ],
        ]);
        Log::info('[MetaCatalogBulk] completed', [
            'queued_items' => $synced,
            'failed_to_queue' => $failed,
        ]);
    }
}
