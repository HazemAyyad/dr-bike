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

    public function __construct(
        public ?int $whatsappAccountId = null,
        public string $sourceType = 'all',
        public ?int $sourceId = null,
    )
    {
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return implode(':', [
            'meta-catalog-hierarchy-finalize',
            $this->whatsappAccountId ?: 'default',
            $this->sourceType,
            $this->sourceId ?: 'all',
        ]);
    }

    public function handle(MetaCatalogHierarchyService $service): void
    {
        Log::info('[MetaCatalogHierarchyFinalize] started', [
            'whatsapp_account_id' => $this->whatsappAccountId,
            'source_type' => $this->sourceType,
            'source_id' => $this->sourceId,
        ]);
        $result = $service->syncSource($this->sourceType, $this->sourceId, $this->whatsappAccountId);
        Log::info('[MetaCatalogHierarchyFinalize] completed', $result);
    }
}
