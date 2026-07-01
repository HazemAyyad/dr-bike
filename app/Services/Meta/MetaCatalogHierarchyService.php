<?php

namespace App\Services\Meta;

use App\Models\Category;
use App\Models\MetaCatalogProductSet;
use App\Models\Product;
use App\Models\SubCategory;
use App\Models\SubCategoryProduct;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class MetaCatalogHierarchyService
{
    public function __construct(private readonly MetaCatalogService $meta)
    {
    }

    public function syncAll(): array
    {
        $this->meta->validateConfig();
        $result = ['synced' => 0, 'failed' => 0, 'deleted' => 0, 'errors' => []];
        Log::info('[MetaCatalogHierarchy] sync started', [
            'categories' => Category::query()->count(),
            'sub_categories' => SubCategory::query()->count(),
        ]);

        Category::query()
            ->with('subCategories.category')
            ->orderBy('id')
            ->chunkById(100, function ($categories) use (&$result) {
                foreach ($categories as $category) {
                    $this->syncSafely($category, $result);
                    foreach ($category->subCategories as $subCategory) {
                        $this->syncSafely($subCategory, $result);
                    }
                }
            });

        $this->deleteOrphanedSets($result);
        Log::info('[MetaCatalogHierarchy] sync completed', $result);

        return $result;
    }

    public function syncCategory(Category $category): MetaCatalogProductSet
    {
        $name = trim((string) ($category->nameAr ?: $category->nameEng ?: 'Category '.$category->id));
        return $this->syncSet(
            sourceType: 'category',
            sourceId: (int) $category->id,
            parentSourceId: null,
            name: $name,
            filterField: 'custom_label_0',
            filterValue: 'DRBIKE-C-'.$category->id,
            filter: ['custom_label_0' => ['i_contains' => 'DRBIKE-C-'.$category->id]],
            enabled: (bool) $category->isShow,
        );
    }

    public function syncSubCategory(SubCategory $subCategory): MetaCatalogProductSet
    {
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
            enabled: (bool) $subCategory->isShow && (bool) ($subCategory->category?->isShow ?? true),
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
    ): MetaCatalogProductSet {
        $set = MetaCatalogProductSet::query()->firstOrNew([
            'source_type' => $sourceType,
            'source_id' => $sourceId,
        ]);
        $set->fill([
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
                $this->meta->deleteProductSet($set->meta_product_set_id);
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
                ? $this->meta->updateProductSet($set->meta_product_set_id, $set->name, $filter)
                : $this->meta->createProductSet($set->name, $filter);
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

    private function syncSafely(Model $source, array &$result): void
    {
        try {
            $source instanceof Category
                ? $this->syncCategory($source)
                : $this->syncSubCategory($source);
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

    private function deleteOrphanedSets(array &$result): void
    {
        MetaCatalogProductSet::query()->chunkById(100, function ($sets) use (&$result) {
            foreach ($sets as $set) {
                $exists = $set->source_type === 'category'
                    ? Category::query()->whereKey($set->source_id)->exists()
                    : SubCategory::query()->whereKey($set->source_id)->exists();
                if ($exists || $this->localMemberCount($set) > 0) continue;
                try {
                    $this->deleteSet($set);
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
}
