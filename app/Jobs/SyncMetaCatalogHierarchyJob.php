<?php

namespace App\Jobs;

use App\Services\Meta\MetaCatalogHierarchyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncMetaCatalogHierarchyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(public bool $resyncProducts = true)
    {
        $this->afterCommit();
    }

    public function handle(MetaCatalogHierarchyService $service): void
    {
        Log::info('[MetaCatalogHierarchyJob] started', ['resync_products' => $this->resyncProducts]);
        try {
            $result = $service->syncAll();
            if ($this->resyncProducts) {
                BulkSyncMetaCatalogJob::dispatch()->onConnection('database');
            }
            Log::info('[MetaCatalogHierarchyJob] completed', $result);
        } catch (\Throwable $e) {
            Log::error('[MetaCatalogHierarchyJob] failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }
}
