<?php

namespace App\Observers;

use App\Jobs\SyncMetaCatalogHierarchyJob;
use App\Jobs\SyncMetaCatalogProductJob;
use App\Models\AppSetting;
use App\Models\Category;
use App\Models\SubCategory;
use App\Models\SubCategoryProduct;
use Illuminate\Database\Eloquent\Model;

class MetaCatalogHierarchyObserver
{
    public function saved(Model $model): void
    {
        if (! AppSetting::getBool(AppSetting::KEY_AUTO_SYNC_META_CATALOG)) return;
        if ($model instanceof SubCategoryProduct) {
            SyncMetaCatalogProductJob::dispatch((int) $model->product_id);
            return;
        }
        SyncMetaCatalogHierarchyJob::dispatch(false);
        $this->queueAffectedProducts($model);
    }

    public function deleted(Model $model): void
    {
        if (! AppSetting::getBool(AppSetting::KEY_AUTO_SYNC_META_CATALOG)) return;
        if ($model instanceof SubCategoryProduct) {
            SyncMetaCatalogProductJob::dispatch((int) $model->product_id);
            return;
        }
        SyncMetaCatalogHierarchyJob::dispatch(false);
    }

    private function queueAffectedProducts(Model $model): void
    {
        if ($model instanceof Category) {
            $model->products()->select('products.id')->chunkById(100, function ($products) {
                foreach ($products as $product) {
                    SyncMetaCatalogProductJob::dispatch((int) $product->id);
                }
            });
            return;
        }
        if ($model instanceof SubCategory) {
            SubCategoryProduct::query()
                ->where('sub_category_id', $model->id)
                ->select(['id', 'product_id'])
                ->chunkById(100, function ($rows) {
                    foreach ($rows as $row) {
                        SyncMetaCatalogProductJob::dispatch((int) $row->product_id);
                    }
                });
        }
    }
}
