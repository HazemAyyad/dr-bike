<?php

namespace App\Observers;

use App\Jobs\SyncMetaCatalogProductJob;
use App\Models\AppSetting;
use Illuminate\Database\Eloquent\Model;

class ProductImageMetaCatalogObserver
{
    public function saved(Model $image): void
    {
        $this->queue($image);
    }

    public function deleted(Model $image): void
    {
        $this->queue($image);
    }

    private function queue(Model $image): void
    {
        $productId = (int) ($image->getAttribute('itemId') ?? 0);
        if ($productId <= 0 || ! AppSetting::getBool(AppSetting::KEY_AUTO_SYNC_META_CATALOG)) return;
        SyncMetaCatalogProductJob::dispatch($productId);
    }
}
