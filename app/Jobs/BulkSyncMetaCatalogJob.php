<?php

namespace App\Jobs;

use App\Models\MetaCatalogSyncLog;
use App\Models\Product;
use App\Models\WhatsAppAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class BulkSyncMetaCatalogJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $uniqueFor = 3600;

    public function __construct(
        public bool $onlyMembershipChanges = false,
        public bool $finalizeHierarchy = false,
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
            $this->onlyMembershipChanges ? 'meta-catalog-membership-bulk' : 'meta-catalog-full-bulk',
            $this->whatsappAccountId ?: 'default',
            $this->sourceType,
            $this->sourceId ?: 'all',
        ]);
    }

    public function handle(): void
    {
        $synced = 0;
        $failed = 0;
        $skipped = 0;
        Log::info('[MetaCatalogBulk] started', [
            'only_membership_changes' => $this->onlyMembershipChanges,
            'whatsapp_account_id' => $this->whatsappAccountId,
            'source_type' => $this->sourceType,
            'source_id' => $this->sourceId,
        ]);
        $account = $this->whatsappAccountId
            ? WhatsAppAccount::query()->find($this->whatsappAccountId)
            : null;
        $query = Product::query()
            ->where('isShow', true)
            ->with(['sizes.colorSizes', 'subCategories']);
        $this->applySourceFilter($query);
        $query->chunkById(100, function ($products) use (&$synced, &$failed, &$skipped) {
                foreach ($products as $product) {
                    [$mainLabel, $subLabel] = $this->expectedMembershipLabels($product);
                    $variants = $product->sizes->flatMap->colorSizes;
                    if ($variants->isEmpty()) {
                        if ($this->onlyMembershipChanges
                            && ! $this->membershipChanged($product, $mainLabel, $subLabel)) {
                            $skipped++;
                            continue;
                        }
                        try {
                            SyncMetaCatalogProductJob::dispatch((int) $product->id, null, $this->whatsappAccountId)
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
                        if ($this->onlyMembershipChanges
                            && ! $this->membershipChanged($variant, $mainLabel, $subLabel)) {
                            $skipped++;
                            continue;
                        }
                        try {
                            SyncMetaCatalogProductJob::dispatch((int) $product->id, (int) $variant->id, $this->whatsappAccountId)
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
            'whatsapp_account_id' => $account?->id,
            'catalog_id' => $account?->catalog_id ?: config('meta_commerce.catalog_id'),
            'response_payload' => [
                'queued_at' => now()->toIso8601String(),
                'queued_items' => $synced,
                'failed_to_queue' => $failed,
                'skipped_unchanged_or_invalid' => $skipped,
            ],
        ]);
        Log::info('[MetaCatalogBulk] completed', [
            'queued_items' => $synced,
            'failed_to_queue' => $failed,
            'skipped_unchanged_or_invalid' => $skipped,
        ]);
        if ($this->finalizeHierarchy) {
            // This is queued after all product jobs inserted above, so a
            // single FIFO database worker updates sets after memberships.
            FinalizeMetaCatalogHierarchyJob::dispatch($this->whatsappAccountId, $this->sourceType, $this->sourceId)
                ->onConnection('database');
        }
    }

    private function applySourceFilter($query): void
    {
        if ($this->sourceType === 'category' && $this->sourceId) {
            $query->where('category_id', $this->sourceId);
            return;
        }

        if ($this->sourceType === 'sub_category' && $this->sourceId) {
            $query->whereHas('subCategories', fn ($pivots) => $pivots->where('sub_category_id', $this->sourceId));
        }
    }

    private function membershipChanged(Product|\App\Models\SizeColor $target, ?string $mainLabel, ?string $subLabel): bool
    {
        // Hierarchy refreshes must not endlessly retry ineligible/pending items.
        // Full bulk sync remains available explicitly for those products.
        if ($target->meta_catalog_sync_status !== 'synced') {
            return false;
        }
        $payload = is_array($target->meta_catalog_payload) ? $target->meta_catalog_payload : [];

        return ($payload['custom_label_0'] ?? null) !== $mainLabel
            || ($payload['custom_label_1'] ?? null) !== $subLabel;
    }

    private function expectedMembershipLabels(Product $product): array
    {
        $main = $product->category_id ? 'DRBIKE-C-'.$product->category_id : null;
        $sub = $product->subCategories
            ->pluck('sub_category_id')
            ->filter()
            ->unique()
            ->sort()
            ->map(fn ($id) => '|DRBIKE-S-'.$id.'|')
            ->implode('');

        return [$main, $sub !== '' ? mb_substr($sub, 0, 100) : null];
    }
}
