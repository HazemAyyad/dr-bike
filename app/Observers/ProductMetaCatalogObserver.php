<?php

namespace App\Observers;

use App\Jobs\DeleteMetaCatalogItemJob;
use App\Jobs\SyncMetaCatalogProductJob;
use App\Models\AppSetting;
use App\Models\Product;

class ProductMetaCatalogObserver
{
    private const SYNC_FIELDS = [
        'nameAr', 'nameEng', 'descriptionAr', 'descriptionEng', 'normailPrice',
        'price', 'stock', 'isShow', 'category_id',
    ];

    public function created(Product $product): void
    {
        $product->forceFill(['meta_catalog_sync_status' => 'pending'])->saveQuietly();
        $this->dispatchIfEnabled($product);
    }

    public function updated(Product $product): void
    {
        if (! $product->wasChanged(self::SYNC_FIELDS)) return;
        $product->forceFill(['meta_catalog_sync_status' => 'pending'])->saveQuietly();
        if ($product->wasChanged('isShow') && ! $product->isShow) {
            $product->loadMissing('sizes.colorSizes');
            $variants = $product->sizes->flatMap->colorSizes;
            if ($variants->isEmpty() && $product->meta_catalog_item_id) {
                DeleteMetaCatalogItemJob::dispatch((int) $product->id);
            }
            foreach ($variants as $variant) {
                if ($variant->meta_catalog_item_id) {
                    DeleteMetaCatalogItemJob::dispatch((int) $product->id, (int) $variant->id);
                }
            }
            return;
        }
        $this->dispatchIfEnabled($product);
    }

    public function deleted(Product $product): void
    {
        $product->loadMissing('sizes.colorSizes');
        $variants = $product->sizes->flatMap->colorSizes;
        if ($variants->isEmpty() && $product->meta_catalog_item_id) {
            DeleteMetaCatalogItemJob::dispatch((int) $product->id);
        }
        foreach ($variants as $variant) {
            if (! $variant->meta_catalog_item_id) continue;
            DeleteMetaCatalogItemJob::dispatch(
                (int) $product->id,
                (int) $variant->id,
                (string) $variant->meta_catalog_item_id,
                $variant->meta_catalog_retailer_id,
            );
        }
    }

    private function dispatchIfEnabled(Product $product): void
    {
        if (! AppSetting::getBool(AppSetting::KEY_AUTO_SYNC_META_CATALOG)) return;
        $product->loadMissing('sizes.colorSizes');
        $variants = $product->sizes->flatMap->colorSizes;
        if ($variants->isEmpty()) {
            SyncMetaCatalogProductJob::dispatch((int) $product->id);
            return;
        }
        foreach ($variants as $variant) {
            SyncMetaCatalogProductJob::dispatch((int) $product->id, (int) $variant->id);
        }
    }
}
