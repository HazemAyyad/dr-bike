<?php

namespace App\Services\Meta;

use App\Models\Category;
use App\Models\MetaCatalogProductSet;
use App\Models\MetaCatalogProductSync;
use App\Models\Product;
use App\Models\SubCategory;
use App\Models\SubCategoryProduct;
use App\Models\WhatsAppAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class MetaCatalogHierarchyService
{
    public function __construct(private readonly MetaCatalogService $meta)
    {
    }

    public function syncAll(?int $whatsappAccountId = null): array
    {
        $account = $whatsappAccountId ? WhatsAppAccount::query()->find($whatsappAccountId) : null;
        $meta = $account ? $this->meta->forAccount($account) : $this->meta;
        $meta->validateConfig();
        $result = ['synced' => 0, 'failed' => 0, 'deleted' => 0, 'errors' => []];
        Log::info('[MetaCatalogHierarchy] sync started', [
            'whatsapp_account_id' => $account?->id,
            'categories' => Category::query()->count(),
            'sub_categories' => SubCategory::query()->count(),
        ]);

        Category::query()
            ->with('subCategories.category')
            ->orderBy('id')
            ->chunkById(100, function ($categories) use (&$result, $account, $meta) {
                foreach ($categories as $category) {
                    $this->syncSafely($category, $result, $account, $meta);
                    foreach ($category->subCategories as $subCategory) {
                        $this->syncSafely($subCategory, $result, $account, $meta);
                    }
                }
            });

        $this->deleteOrphanedSets($result, $account, $meta);
        Log::info('[MetaCatalogHierarchy] sync completed', $result);

        return $result;
    }

    public function syncSource(string $sourceType = 'all', ?int $sourceId = null, ?int $whatsappAccountId = null): array
    {
        if ($sourceType === 'all') {
            return $this->syncAll($whatsappAccountId);
        }

        $account = $whatsappAccountId ? WhatsAppAccount::query()->find($whatsappAccountId) : null;
        $meta = $account ? $this->meta->forAccount($account) : $this->meta;
        $meta->validateConfig();
        $result = ['synced' => 0, 'failed' => 0, 'deleted' => 0, 'errors' => []];

        try {
            if ($sourceType === 'category') {
                $category = Category::query()->findOrFail((int) $sourceId);
                $this->syncCategory($category, $account, $meta);
                $result['synced']++;
                return $result;
            }

            if ($sourceType === 'sub_category') {
                $subCategory = SubCategory::query()->with('category')->findOrFail((int) $sourceId);
                $this->syncSubCategory($subCategory, $account, $meta);
                $result['synced']++;

                if ($subCategory->category) {
                    $this->syncCategory($subCategory->category, $account, $meta);
                    $result['synced']++;
                }

                return $result;
            }

            throw new RuntimeException('نوع نطاق المزامنة غير مدعوم.');
        } catch (Throwable $e) {
            $result['failed']++;
            $result['errors'][] = [
                'type' => $sourceType,
                'id' => $sourceId,
                'message' => $e->getMessage(),
            ];
            throw $e;
        }
    }

    public function syncCategory(Category $category, ?WhatsAppAccount $account = null, ?MetaCatalogService $meta = null): MetaCatalogProductSet
    {
        $meta ??= $account ? $this->meta->forAccount($account) : $this->meta;
        $name = trim((string) ($category->nameAr ?: $category->nameEng ?: 'Category '.$category->id));
        return $this->syncSet(
            sourceType: 'category',
            sourceId: (int) $category->id,
            parentSourceId: null,
            name: $name,
            filterField: 'custom_label_0',
            filterValue: 'DRBIKE-C-'.$category->id,
            filter: ['custom_label_0' => ['i_contains' => 'DRBIKE-C-'.$category->id]],
            enabled: (bool) $category->isShow
                && $this->hasSyncedMembers('category', (int) $category->id, $account),
            account: $account,
            meta: $meta,
        );
    }

    public function syncSubCategory(SubCategory $subCategory, ?WhatsAppAccount $account = null, ?MetaCatalogService $meta = null): MetaCatalogProductSet
    {
        $meta ??= $account ? $this->meta->forAccount($account) : $this->meta;
        $subCategory->loadMissing('category');
        $parentName = trim((string) ($subCategory->category?->nameAr ?: $subCategory->category?->nameEng));
        $childName = trim((string) ($subCategory->nameAr ?: $subCategory->nameEng ?: 'Subcategory '.$subCategory->id));
        $name = $parentName !== '' ? $parentName.' / '.$childName : $childName;
        $token = '|DRBIKE-S-'.$subCategory->id.'|';

        return $this->syncSet(
            sourceType: 'sub_category',
            sourceId: (int) $subCategory->id,
            parentSourceId: (int) $subCategory->mainCategoryId,
            name: $name,
            filterField: 'custom_label_1',
            filterValue: $token,
            filter: ['custom_label_1' => ['i_contains' => $token]],
            enabled: (bool) $subCategory->isShow
                && (bool) ($subCategory->category?->isShow ?? true)
                && $this->hasSyncedMembers('sub_category', (int) $subCategory->id, $account),
            account: $account,
            meta: $meta,
        );
    }

    public function deleteSet(MetaCatalogProductSet $set): void
    {
        if ($this->localMemberCount($set) > 0) {
            throw new RuntimeException('لا يمكن حذف مجموعة Meta قبل إزالة المنتجات المرتبطة بها محلياً.');
        }
        if ($set->meta_product_set_id) {
            $this->meta->deleteProductSet($set->meta_product_set_id);
        }
        $set->delete();
    }

    private function syncSet(
        string $sourceType,
        int $sourceId,
        ?int $parentSourceId,
        string $name,
        string $filterField,
        string $filterValue,
        array $filter,
        bool $enabled,
        ?WhatsAppAccount $account = null,
        ?MetaCatalogService $meta = null,
    ): MetaCatalogProductSet {
        $meta ??= $account ? $this->meta->forAccount($account) : $this->meta;
        $catalogId = $account?->catalog_id ?: config('meta_commerce.catalog_id');
        $set = MetaCatalogProductSet::query()->firstOrNew([
            'catalog_id' => $catalogId,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
        ]);
        $set->fill([
            'whatsapp_account_id' => $account?->id,
            'catalog_id' => $catalogId,
            'parent_source_id' => $parentSourceId,
            'name' => mb_substr($name, 0, 100),
            'filter_field' => $filterField,
            'filter_value' => $filterValue,
            'filter_payload' => $filter,
            'sync_status' => 'pending',
            'last_error' => null,
        ])->save();

        if (! $enabled) {
            if ($set->meta_product_set_id) {
                $meta->deleteProductSet($set->meta_product_set_id);
            }
            $set->forceFill([
                'meta_product_set_id' => null,
                'sync_status' => 'disabled',
                'last_synced_at' => now(),
            ])->save();
            return $set->fresh();
        }

        try {
            $response = $set->meta_product_set_id
                ? $meta->updateProductSet($set->meta_product_set_id, $set->name, $filter)
                : $meta->createProductSet($set->name, $filter);
            $set->forceFill([
                'meta_product_set_id' => (string) ($response['id'] ?? $set->meta_product_set_id),
                'sync_status' => 'synced',
                'last_synced_at' => now(),
                'last_error' => null,
            ])->save();
            return $set->fresh();
        } catch (Throwable $e) {
            $set->forceFill(['sync_status' => 'failed', 'last_error' => $e->getMessage()])->save();
            throw $e;
        }
    }

    private function syncSafely(Model $source, array &$result, ?WhatsAppAccount $account, MetaCatalogService $meta): void
    {
        try {
            $source instanceof Category
                ? $this->syncCategory($source, $account, $meta)
                : $this->syncSubCategory($source, $account, $meta);
            $result['synced']++;
        } catch (Throwable $e) {
            $result['failed']++;
            $result['errors'][] = [
                'type' => $source instanceof Category ? 'category' : 'sub_category',
                'id' => $source->getKey(),
                'message' => $e->getMessage(),
            ];
            Log::error('[MetaCatalogHierarchy] set sync failed', [
                'source_type' => $source instanceof Category ? 'category' : 'sub_category',
                'source_id' => $source->getKey(),
                'name' => $source->nameAr ?? $source->nameEng ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function deleteOrphanedSets(array &$result, ?WhatsAppAccount $account, MetaCatalogService $meta): void
    {
        MetaCatalogProductSet::query()
            ->when($account, fn ($query) => $query->where('catalog_id', $this->catalogIdForAccount($account)))
            ->when(! $account, fn ($query) => $query->whereNull('whatsapp_account_id'))
            ->chunkById(100, function ($sets) use (&$result, $meta) {
            foreach ($sets as $set) {
                $exists = $set->source_type === 'category'
                    ? Category::query()->whereKey($set->source_id)->exists()
                    : SubCategory::query()->whereKey($set->source_id)->exists();
                if ($exists || $this->localMemberCount($set) > 0) continue;
                try {
                    if ($set->meta_product_set_id) {
                        $meta->deleteProductSet($set->meta_product_set_id);
                    }
                    $set->delete();
                    $result['deleted']++;
                } catch (Throwable $e) {
                    $set->forceFill(['sync_status' => 'failed', 'last_error' => $e->getMessage()])->save();
                    $result['failed']++;
                    Log::error('[MetaCatalogHierarchy] orphan set delete failed', [
                        'set_id' => $set->id,
                        'meta_product_set_id' => $set->meta_product_set_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });
    }

    private function localMemberCount(MetaCatalogProductSet $set): int
    {
        return $set->source_type === 'category'
            ? Product::query()->where('category_id', $set->source_id)->count()
            : SubCategoryProduct::query()->where('sub_category_id', $set->source_id)->count();
    }

    private function hasSyncedMembers(string $sourceType, int $sourceId, ?WhatsAppAccount $account = null): bool
    {
        if ($account) {
            $query = MetaCatalogProductSync::query()
                ->where('catalog_id', $this->catalogIdForAccount($account))
                ->where('sync_status', 'synced')
                ->whereHas('product', fn ($products) => $products->where('isShow', true));

            if ($sourceType === 'category') {
                $query->whereHas('product', fn ($products) => $products->where('category_id', $sourceId));
            } else {
                $query->whereHas('product.subCategories', fn ($pivots) => $pivots->where('sub_category_id', $sourceId));
            }

            return $query->exists();
        }

        $query = Product::query()
            ->where('isShow', true)
            ->where(function ($q) {
                $q->where('meta_catalog_sync_status', 'synced')
                    ->orWhereHas('sizes.colorSizes', fn ($variants) => $variants->where('meta_catalog_sync_status', 'synced'));
            });

        if ($sourceType === 'category') {
            $query->where('category_id', $sourceId);
        } else {
            $query->whereHas('subCategories', fn ($pivots) => $pivots->where('sub_category_id', $sourceId));
        }

        return $query->exists();
    }

    private function catalogIdForAccount(?WhatsAppAccount $account): ?string
    {
        return $account?->catalog_id ?: config('meta_commerce.catalog_id');
    }
}
