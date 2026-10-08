<?php

namespace App\Services\OnlineStore;

use App\Models\OnlineStore\OnlineStoreCategory;
use App\Models\OnlineStore\OnlineStoreListing;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class OnlineStoreCategoryService
{
    public function __construct(private readonly ListingLifecycleService $lifecycle) {}

    public function save(?OnlineStoreCategory $category, array $data, User $actor): OnlineStoreCategory
    {
        return DB::transaction(function () use ($category, $data, $actor) {
            $category ??= new OnlineStoreCategory;
            if (! $category->exists && ! array_key_exists('sort_order', $data)) {
                $siblings = OnlineStoreCategory::query();
                isset($data['parent_id'])
                    ? $siblings->where('parent_id', $data['parent_id'])
                    : $siblings->whereNull('parent_id');
                $data['sort_order'] = ((int) $siblings->max('sort_order')) + 1;
            }
            if (array_key_exists('parent_id', $data)) {
                $this->assertNoCycle($category, $data['parent_id']);
            }
            $wasActive = $category->exists ? $category->is_active : null;
            $category->fill($data);
            if (! $category->exists) {
                $category->created_by = $actor->getKey();
            }
            $category->updated_by = $actor->getKey();
            $category->save();
            if ($wasActive !== null && $wasActive !== $category->is_active) {
                $this->refreshMemberListings($category, $actor);
            }

            return $category->fresh(['parent']);
        });
    }

    public function replaceMemberships(OnlineStoreCategory $category, array $items, User $actor): OnlineStoreCategory
    {
        return DB::transaction(function () use ($category, $items, $actor) {
            $listingIds = collect($items)->pluck('listing_id')->map(fn ($id) => (int) $id);
            if ($listingIds->duplicates()->isNotEmpty() || OnlineStoreListing::query()->whereIn('id', $listingIds)->count() !== $listingIds->count()) {
                throw ValidationException::withMessages(['items' => ['Memberships must contain unique existing Online Store listings.']]);
            }
            $affected = $category->memberships()->pluck('online_store_listing_id')->merge($listingIds)->unique();
            $category->memberships()->delete();
            foreach ($items as $item) {
                $category->memberships()->create(['online_store_listing_id' => $item['listing_id'], 'sort_order' => $item['sort_order']]);
            }
            OnlineStoreListing::query()->whereIn('id', $affected)->get()->each(fn ($listing) => $this->lifecycle->refresh($listing, $actor));

            return $category->fresh('memberships');
        });
    }

    public function reorder(?int $parentId, array $orderedIds): void
    {
        DB::transaction(function () use ($parentId, $orderedIds) {
            $query = OnlineStoreCategory::query()->where('parent_id', $parentId);
            $actual = $query->lockForUpdate()->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
            $provided = array_map('intval', $orderedIds);
            $sortedProvided = $provided;
            sort($sortedProvided);
            $sortedActual = $actual;
            sort($sortedActual);
            if (count($provided) !== count(array_unique($provided)) || $sortedProvided !== $sortedActual) {
                throw ValidationException::withMessages(['category_ids' => ['A reorder must include every sibling exactly once.']]);
            }
            foreach ($provided as $order => $id) {
                OnlineStoreCategory::query()->whereKey($id)->update(['sort_order' => $order]);
            }
        });
    }

    public function delete(OnlineStoreCategory $category, User $actor, ?int $replacementCategoryId = null): string
    {
        return DB::transaction(function () use ($category, $actor, $replacementCategoryId) {
            if ($category->children()->exists()) {
                throw ValidationException::withMessages(['category' => ['Move or remove child categories first.']]);
            }

            $listingIds = $category->memberships()
                ->lockForUpdate()
                ->pluck('online_store_listing_id')
                ->map(fn ($id) => (int) $id);
            if ($listingIds->isNotEmpty() && $replacementCategoryId === null) {
                throw ValidationException::withMessages([
                    'replacement_category_id' => ['Choose a destination category for the products first.'],
                ]);
            }
            if ($replacementCategoryId !== null) {
                $replacement = OnlineStoreCategory::query()->lockForUpdate()->find($replacementCategoryId);
                if (! $replacement || ! $replacement->is_active) {
                    throw ValidationException::withMessages([
                        'replacement_category_id' => ['The destination category must be active.'],
                    ]);
                }
                $nextOrder = (int) $replacement->memberships()->max('sort_order') + 1;
                foreach ($listingIds as $listingId) {
                    $replacement->memberships()->firstOrCreate(
                        ['online_store_listing_id' => $listingId],
                        ['sort_order' => $nextOrder++]
                    );
                }
                $category->memberships()->delete();
                OnlineStoreListing::query()->whereIn('id', $listingIds)->get()
                    ->each(fn ($listing) => $this->lifecycle->refresh($listing, $actor));
            }

            $hasBusinessReferences = DB::table('online_store_home_section_items')
                    ->where('target_type', 'category')
                    ->where('target_id', $category->getKey())
                    ->exists()
                || DB::table('online_store_promotion_targets')
                    ->where('target_type', 'category')
                    ->where('target_id', $category->getKey())
                    ->exists()
                || DB::table('online_store_coupon_targets')
                    ->where('target_type', 'category')
                    ->where('target_id', $category->getKey())
                    ->exists();

            if (! $hasBusinessReferences) {
                $category->delete();

                return 'deleted';
            }

            $wasActive = $category->is_active;
            $category->forceFill(['is_active' => false, 'show_on_home' => false, 'updated_by' => $actor->getKey()])->save();
            if ($wasActive) {
                $this->refreshMemberListings($category, $actor);
            }

            return 'deactivated';
        });
    }

    private function assertNoCycle(OnlineStoreCategory $category, mixed $parentId): void
    {
        if ($parentId === null) {
            return;
        }
        if ($category->exists && (int) $parentId === (int) $category->getKey()) {
            throw ValidationException::withMessages(['parent_id' => ['A category cannot be its own parent.']]);
        }
        $seen = [];
        while ($parentId !== null) {
            if (isset($seen[$parentId]) || ($category->exists && (int) $parentId === (int) $category->getKey())) {
                throw ValidationException::withMessages(['parent_id' => ['The category hierarchy cannot contain a cycle.']]);
            }
            $seen[$parentId] = true;
            $parentId = OnlineStoreCategory::query()->whereKey($parentId)->value('parent_id');
        }
    }

    private function refreshMemberListings(OnlineStoreCategory $category, User $actor): void
    {
        OnlineStoreListing::query()->whereIn('id', $category->memberships()->pluck('online_store_listing_id'))->get()->each(fn ($listing) => $this->lifecycle->refresh($listing, $actor));
    }
}
