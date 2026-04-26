<?php

namespace App\Services;

use App\Models\ProductTag;
use Illuminate\Support\Facades\DB;

class ProductTagService
{
    /**
     * Replace all tag links for a product (empty array removes all).
     *
     * @param  array<int, mixed>  $tagIds
     */
    public function syncTagsForProduct(int $productId, array $tagIds): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $tagIds))));
        DB::table('product_product_tag')->where('product_id', $productId)->delete();
        foreach ($ids as $tagId) {
            if ($tagId <= 0 || ! ProductTag::query()->whereKey($tagId)->exists()) {
                continue;
            }
            DB::table('product_product_tag')->insert([
                'product_id' => $productId,
                'product_tag_id' => $tagId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * @param  array<int, mixed>  $tagIds
     */
    public function attachTags(int $productId, array $tagIds): void
    {
        foreach (array_unique(array_filter(array_map('intval', $tagIds))) as $tagId) {
            if ($tagId <= 0 || ! ProductTag::query()->whereKey($tagId)->exists()) {
                continue;
            }
            DB::table('product_product_tag')->updateOrInsert(
                ['product_id' => $productId, 'product_tag_id' => $tagId],
                ['created_at' => now(), 'updated_at' => now()]
            );
        }
    }

    /**
     * @param  array<int, mixed>  $tagIds
     */
    public function detachTags(int $productId, array $tagIds): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $tagIds))));
        if ($ids === []) {
            return;
        }
        DB::table('product_product_tag')
            ->where('product_id', $productId)
            ->whereIn('product_tag_id', $ids)
            ->delete();
    }
}
