<?php

namespace App\Jobs;

use App\Services\Meta\MetaCatalogHierarchyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

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
        $service->syncAll();
        if ($this->resyncProducts) {
            BulkSyncMetaCatalogJob::dispatch();
        }
    }
}
