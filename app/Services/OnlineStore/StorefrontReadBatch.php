<?php

namespace App\Services\OnlineStore;

use App\Models\OnlineStore\OnlineStorePromotion;
use App\Services\SalesOrderStockService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Read-only request-local data; never used for checkout or cached across requests. */
final class StorefrontReadBatch
{
    private array $listingIds = [];

    private array $productIds = [];

    private array $sources = [];

    private Collection $memberships;

    private Collection $stock;

    private Collection $promotions;

    public function prepare(Collection $listings): void
    {
        $listings = new \Illuminate\Database\Eloquent\Collection($listings->all());
        $this->listingIds = $listings->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->productIds = $listings->pluck('product_id')->unique()->values()->all();
        $listings->loadMissing(['product.sizes.colorSizes', 'mediaPresentations']);
        $this->sources = [];
        foreach (['view_image' => 'view_image_products', 'normal_image' => 'normal_image_products', 'image3d' => 'image3d_products'] as $type => $table) {
            $this->sources[$type] = DB::table($table)->whereIn('itemId', $this->productIds)
                ->get(['id', 'itemId', 'imageUrl'])->keyBy('id');
        }
        $this->sources['variant'] = DB::table('size_colors')->join('sizes', 'sizes.id', '=', 'size_colors.sizeId')
            ->whereIn('sizes.itemId', $this->productIds)
            ->get(['size_colors.id', 'sizes.itemId', 'size_colors.image_url as imageUrl'])->keyBy('id');
        $this->memberships = DB::table('online_store_category_listing as membership')
            ->join('online_store_categories as category', 'category.id', '=', 'membership.online_store_category_id')
            ->whereIn('membership.online_store_listing_id', $this->listingIds)->where('category.is_active', true)
            ->get(['membership.online_store_listing_id', 'membership.online_store_category_id'])->groupBy('online_store_listing_id');
        $this->stock = collect(app(SalesOrderStockService::class)->bulkAvailability($this->productIds))->groupBy('product_id');
        $this->promotions = OnlineStorePromotion::query()->with('targets')->where('is_active', true)
            ->orderByDesc('priority')->orderBy('id')->get();
    }

    public function hasListing(int $id): bool
    {
        return in_array($id, $this->listingIds, true);
    }

    public function hasProduct(int $id): bool
    {
        return in_array($id, $this->productIds, true);
    }

    public function stock(int $id): array
    {
        return $this->stock->get($id, collect())->all();
    }

    public function categories(int $id): array
    {
        return $this->memberships->get($id, collect())->pluck('online_store_category_id')->map(fn ($id) => (int) $id)->all();
    }

    public function promotions(): Collection
    {
        return $this->promotions;
    }

    public function sourcePath(string $type, int $sourceId, int $productId): ?string
    {
        $source = ($this->sources[$type] ?? collect())->get($sourceId);

        return $source && (int) $source->itemId === $productId && filled($source->imageUrl) ? $source->imageUrl : null;
    }

    public function clear(): void
    {
        $this->listingIds = $this->productIds = $this->sources = [];
    }

    public static function variantRows(\App\Models\Product $product): Collection
    {
        return $product->sizes->sortBy('id')->flatMap(fn ($size) => $size->colorSizes->sortBy('id')->map(fn ($variant) => (object) [
            'id' => $variant->id, 'sizeId' => $variant->sizeId,
            'normailPrice' => $variant->normailPrice, 'wholesalePrice' => $variant->wholesalePrice,
            'size_id' => $size->id, 'size' => $size->size, 'description' => $size->description,
            'color_id' => $variant->id, 'colorAr' => $variant->colorAr,
            'colorEn' => $variant->colorEn, 'colorAbbr' => $variant->colorAbbr,
        ]))->values();
    }
}
