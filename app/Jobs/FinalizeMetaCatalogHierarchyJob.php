<?php

namespace App\Jobs;

use App\Services\Meta\MetaCatalogHierarchyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FinalizeMetaCatalogHierarchyJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;
    public int $uniqueFor = 3600;

    public function uniqueId(): string
    {
        return 'meta-catalog-hierarchy-finalize';
    }

    public function handle(MetaCatalogHierarchyService $service): void
    {
        Log::info('[MetaCatalogHierarchyFinalize] started');
        $result = $service->syncAll();
        Log::info('[MetaCatalogHierarchyFinalize] completed', $result);
    }
}
