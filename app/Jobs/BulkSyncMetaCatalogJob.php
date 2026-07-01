<?php

namespace App\Jobs;

use App\Models\MetaCatalogSyncLog;
use App\Models\Product;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class BulkSyncMetaCatalogJob implements ShouldQueue
{
    use Queueable;

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
                        SyncMetaCatalogProductJob::dispatch((int) $product->id);
                        continue;
                    }
                    foreach ($variants as $variant) {
                        SyncMetaCatalogProductJob::dispatch((int) $product->id, (int) $variant->id);
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
