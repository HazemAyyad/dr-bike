<?php

namespace App\Observers;

use App\Jobs\DeleteMetaCatalogItemJob;
use App\Jobs\SyncMetaCatalogProductJob;
use App\Models\AppSetting;
use App\Models\SizeColor;

class SizeColorMetaCatalogObserver
{
    public function created(SizeColor $variant): void
    {
        $variant->forceFill(['meta_catalog_sync_status' => 'pending'])->saveQuietly();
        $this->dispatch($variant);
    }

    public function updated(SizeColor $variant): void
    {
        if (! $variant->wasChanged(['colorAr', 'colorEn', 'normailPrice', 'stock', 'image_url'])) return;
        $variant->forceFill(['meta_catalog_sync_status' => 'pending'])->saveQuietly();
        $this->dispatch($variant);
    }

    public function deleted(SizeColor $variant): void
    {
        $productId = $variant->size?->itemId;
        if ($productId && $variant->meta_catalog_item_id) {
            DeleteMetaCatalogItemJob::dispatch(
                (int) $productId,
                (int) $variant->id,
                (string) $variant->meta_catalog_item_id,
                $variant->meta_catalog_retailer_id,
            );
        }
    }

    private function dispatch(SizeColor $variant): void
    {
        if (! AppSetting::getBool(AppSetting::KEY_AUTO_SYNC_META_CATALOG)) return;
        $productId = $variant->size?->itemId;
        if ($productId) SyncMetaCatalogProductJob::dispatch((int) $productId, (int) $variant->id);
    }
}
