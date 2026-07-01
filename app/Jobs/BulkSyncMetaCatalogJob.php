<?php

namespace App\Jobs;

use App\Models\MetaCatalogSyncLog;
use App\Models\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class BulkSyncMetaCatalogJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function handle(): void
    {
        Product::query()
            ->where('isShow', true)
            ->with('sizes.colorSizes')
            ->chunkById(100, function ($products) {
                foreach ($products as $product) {
                    $variants = $product->sizes->flatMap->colorSizes;
                    if ($variants->isEmpty()) {
                        try {
                            SyncMetaCatalogProductJob::dispatch((int) $product->id);
                        } catch (\Throwable) {
                            // The sync job already stores its failed status/error.
                            // A single invalid product must not abort the bulk run,
                            // especially when QUEUE_CONNECTION=sync.
                        }
                        continue;
                    }
                    foreach ($variants as $variant) {
                        try {
                            SyncMetaCatalogProductJob::dispatch((int) $product->id, (int) $variant->id);
                        } catch (\Throwable) {
                            // Continue syncing the remaining variants/products.
                        }
                    }
                }
            });

        MetaCatalogSyncLog::query()->create([
            'action' => 'bulk_sync',
            'status' => 'success',
            'response_payload' => ['queued_at' => now()->toIso8601String()],
        ]);
    }
}
