<?php

namespace App\Console\Commands;

use App\Services\LegacyInventoryAuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class BackfillInventoryCostLayers extends Command
{
    protected $signature = 'inventory:backfill-cost-layers
        {--write : Create reviewed missing opening layers}
        {--product=* : Limit the audit to specific product IDs}';

    protected $description = 'Dry-run/idempotent opening-cost coverage for legacy product and variant stock.';

    public function handle(LegacyInventoryAuditService $audit): int
    {
        $write = (bool) $this->option('write');

        try {
            $result = $audit->run($write, (array) $this->option('product'));
        } catch (\RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $path = 'inventory-cost-backfill/backfill-'.now()->format('Ymd-His').'.json';
        Storage::disk('local')->put($path, json_encode([
            'mode' => $write ? 'write' : 'dry-run',
            'created_layers' => $result['created_layers'],
            'needs_review' => $result['needs_review'],
            'schema' => $result['schema'],
            'rows' => $result['rows'],
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        $this->info(($write ? 'Created' : 'Audited').' opening layers: '.$result['created_layers']);
        $this->info('Inventory identities needing review: '.$result['needs_review']);
        $this->info('Report: storage/app/'.$path);

        return self::SUCCESS;
    }
}
