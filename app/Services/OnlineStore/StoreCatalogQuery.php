<?php

namespace App\Services\OnlineStore;

use App\Models\OnlineStore\OnlineStorePromotion;
use App\Services\SalesOrderStockService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** SQL projection over the existing authorities; no persisted parallel prices or stock. */
final class StoreCatalogQuery
{
    public function __construct(private readonly StorefrontCatalogService $catalog, private readonly SalesOrderStockService $stock) {}

    public function build(array $filters): Builder
    {
        $query = $this->catalog->eligibleQuery(false)->join('products as catalog_product', 'catalog_product.id', '=', 'online_store_listings.product_id')
            ->whereNull('catalog_product.deleted_at');
        $this->requireReadyContent($query);
        $base = 'GREATEST(0, COALESCE(catalog_product.normailPrice, 0))';
        $price = "COALESCE((SELECT GREATEST(0, ROUND({$base} - LEAST({$base}, GREATEST(0,
            ROUND(CASE WHEN promotion.discount_type = 'percentage' THEN {$base} * promotion.discount_value / 100
            ELSE promotion.discount_value END, 2))), 2))
            FROM online_store_promotions promotion
            WHERE promotion.is_active = 1 AND promotion.applies_to IN ('retail', 'both')
            AND (promotion.starts_at IS NULL OR promotion.starts_at <= ?)
            AND (promotion.ends_at IS NULL OR promotion.ends_at >= ?)
            AND ((promotion.scope = 'global' AND NOT EXISTS (SELECT 1 FROM online_store_promotion_targets target WHERE target.promotion_id = promotion.id))
                OR (promotion.scope = 'targeted' AND EXISTS (SELECT 1 FROM online_store_promotion_targets target
                    WHERE target.promotion_id = promotion.id AND ((target.target_type = 'listing' AND target.target_id = online_store_listings.id)
                    OR (target.target_type = 'category' AND EXISTS (SELECT 1 FROM online_store_category_listing member
                        JOIN online_store_categories category ON category.id = member.online_store_category_id AND category.is_active = 1
                        WHERE member.online_store_listing_id = online_store_listings.id AND member.online_store_category_id = target.target_id))))))
            ORDER BY promotion.priority DESC, promotion.id ASC LIMIT 1), {$base})";
        $reservation = $this->stock->reservationNetSql();
        $variants = DB::table('sizes')->join('size_colors', 'size_colors.sizeId', '=', 'sizes.id')
            ->selectRaw('sizes.itemId as product_id, SUM(size_colors.stock) as physical_stock')->groupBy('sizes.itemId');
        $reserved = DB::table('sales_order_items')->join('sales_orders', 'sales_orders.id', '=', 'sales_order_items.sales_order_id')
            ->where('sales_order_items.is_hidden', false)->where('sales_orders.reserves_stock', true)
            ->whereIn('sales_orders.status', $this->stock->reservingStatuses())
            ->select('sales_order_items.product_id')->selectRaw("SUM({$reservation}) as reserved_qty")->groupBy('sales_order_items.product_id');
        $query->leftJoinSub($variants, 'catalog_variants', 'catalog_variants.product_id', '=', 'catalog_product.id')
            ->leftJoinSub($reserved, 'catalog_reserved', 'catalog_reserved.product_id', '=', 'catalog_product.id');
        $physical = '(CASE WHEN catalog_variants.product_id IS NULL THEN catalog_product.stock ELSE COALESCE(catalog_variants.physical_stock, 0) END)';
        $available = "LEAST(GREATEST(0, {$physical} - COALESCE(catalog_reserved.reserved_qty, 0)), COALESCE(online_store_listings.online_stock_limit, 2147483647))";
        $now = now();
        $hasPromotions = OnlineStorePromotion::query()->where('is_active', true)->whereIn('applies_to', ['retail', 'both'])
            ->where(fn ($active) => $active->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($active) => $active->whereNull('ends_at')->orWhere('ends_at', '>=', $now))->exists();
        if (! $hasPromotions) {
            $price = $base;
        }
        $query->addSelect('online_store_listings.*')->selectRaw("{$base} AS catalog_base_price")
            ->selectRaw("{$price} AS catalog_price", $hasPromotions ? [$now, $now] : [])
            ->selectRaw("{$available} AS catalog_available_qty");
        $term = trim((string) ($filters['q'] ?? ''));
        if ($term !== '') {
            $query->where(fn ($search) => $search->where('online_store_listings.name_translations', 'like', "%{$term}%")
                ->orWhere('catalog_product.nameAr', 'like', "%{$term}%")->orWhere('catalog_product.nameEng', 'like', "%{$term}%")
                ->orWhere('catalog_product.nameAbree', 'like', "%{$term}%")->orWhere('catalog_product.product_code', 'like', "%{$term}%"));
        }
        if (! empty($filters['category_id'])) {
            $query->whereExists(fn ($member) => $member->selectRaw('1')->from('online_store_category_listing as selected_member')
                ->join('online_store_categories as selected_category', 'selected_category.id', '=', 'selected_member.online_store_category_id')
                ->whereColumn('selected_member.online_store_listing_id', 'online_store_listings.id')
                ->where('selected_category.is_active', true)
                ->where('selected_member.online_store_category_id', (int) $filters['category_id']));
        }
        foreach (['minPrice' => '>=', 'maxPrice' => '<='] as $field => $operator) {
            if (isset($filters[$field])) {
                $query->having('catalog_price', $operator, (float) $filters[$field]);
            }
        }
        if (filter_var($filters['availableOnly'] ?? false, FILTER_VALIDATE_BOOL)) {
            $query->having('catalog_available_qty', '>', 0);
        }
        if (filter_var($filters['onSale'] ?? false, FILTER_VALIDATE_BOOL)) {
            $query->havingRaw('catalog_price < catalog_base_price');
        }
        match ($filters['sort'] ?? 'recommended') {
            'price_asc' => $query->orderBy('catalog_price'),
            'price_desc' => $query->orderByDesc('catalog_price'),
            'newest' => $query->orderByDesc('online_store_listings.created_at'),
            'name' => $query->orderBy('catalog_product.nameAr'),
            default => $query->orderBy('online_store_listings.sort_order'),
        };

        return $query->orderBy('online_store_listings.id');
    }

    public function count(Builder $query, array $filters): int
    {
        $required = [];
        if (filter_var($filters['availableOnly'] ?? false, FILTER_VALIDATE_BOOL)) {
            $required[] = 'catalog_available_qty';
        }
        if (isset($filters['minPrice']) || isset($filters['maxPrice']) || filter_var($filters['onSale'] ?? false, FILTER_VALIDATE_BOOL)) {
            $required[] = 'catalog_price';
        }
        if (filter_var($filters['onSale'] ?? false, FILTER_VALIDATE_BOOL)) {
            $required[] = 'catalog_base_price';
        }
        $columns = $query->getQuery()->columns;
        $grammar = $query->getQuery()->getGrammar();
        $priceBindings = array_slice($query->getQuery()->getRawBindings()['select'], -2);
        $count = (clone $query)->reorder()->select('online_store_listings.id');
        foreach ($columns as $column) {
            if (! $column instanceof \Illuminate\Database\Query\Expression) {
                continue;
            }
            $sql = $column->getValue($grammar);
            foreach ($required as $alias) {
                if (str_ends_with($sql, ' AS '.$alias)) {
                    $count->selectRaw($sql, $alias === 'catalog_price' && str_contains($sql, '?') ? $priceBindings : []);
                }
            }
        }

        return (int) $count->toBase()->getCountForPagination();
    }

    private function requireReadyContent(Builder $query): void
    {
        foreach (['name' => ['nameAr', 'nameEng', 'nameAbree'], 'description' => ['descriptionAr', 'descriptionEng', 'descriptionAbree']] as $field => $columns) {
            $query->where(function ($content) use ($field, $columns) {
                // Prefer source text when present; avoid JSON extraction for every
                // locale on the common, fully populated product path.
                foreach ($columns as $column) {
                    $content->orWhereRaw("CHAR_LENGTH(TRIM(COALESCE(catalog_product.{$column}, ''))) > 0");
                }
                foreach (['ar', 'en', 'he'] as $locale) {
                    $content->orWhereRaw("JSON_TYPE(JSON_EXTRACT(online_store_listings.{$field}_translations, '$.{$locale}')) = 'STRING'
                        AND CHAR_LENGTH(TRIM(JSON_UNQUOTE(JSON_EXTRACT(online_store_listings.{$field}_translations, '$.{$locale}')))) > 0");
                }
            });
        }
        $query->where(function ($price) {
            $price->where('catalog_product.normailPrice', '>', 0)->orWhere('catalog_product.wholesalePrice', '>', 0)
                ->orWhereExists(fn ($variant) => $variant->selectRaw('1')->from('sizes as price_size')
                    ->join('size_colors as price_variant', 'price_variant.sizeId', '=', 'price_size.id')
                    ->whereColumn('price_size.itemId', 'catalog_product.id')
                    ->where(fn ($amount) => $amount->where('price_variant.normailPrice', '>', 0)->orWhere('price_variant.wholesalePrice', '>', 0)));
        });
        $query->joinSub($this->readyMedia(), 'catalog_media', 'catalog_media.online_store_listing_id', '=', 'online_store_listings.id');
    }

    private function readyMedia(): \Illuminate\Database\Query\Builder
    {
        $base = fn () => DB::table('online_store_media_presentations as ready_media')
            ->where('ready_media.is_main', true)->where('ready_media.is_visible', true)
            ->select('ready_media.online_store_listing_id');
        $media = $base()->where('ready_media.source_type', 'store_specific')
            ->whereRaw("CHAR_LENGTH(TRIM(COALESCE(ready_media.store_media_path, ''))) > 0");
        foreach (['normal_image' => 'normal_image_products', 'view_image' => 'view_image_products', 'image3d' => 'image3d_products'] as $type => $table) {
            $media->union($base()->where('ready_media.source_type', $type)
                ->join($table.' as ready_image', 'ready_image.id', '=', 'ready_media.source_id')
                ->join('online_store_listings as ready_listing', 'ready_listing.id', '=', 'ready_media.online_store_listing_id')
                ->whereColumn('ready_image.itemId', 'ready_listing.product_id')->whereNotNull('ready_image.imageUrl')->where('ready_image.imageUrl', '<>', ''));
        }
        $media->union($base()->where('ready_media.source_type', 'variant')
            ->join('size_colors as ready_variant', 'ready_variant.id', '=', 'ready_media.source_id')
            ->join('sizes as ready_size', 'ready_size.id', '=', 'ready_variant.sizeId')
            ->join('online_store_listings as ready_listing', 'ready_listing.id', '=', 'ready_media.online_store_listing_id')
            ->whereColumn('ready_size.itemId', 'ready_listing.product_id')->whereNotNull('ready_variant.image_url')->where('ready_variant.image_url', '<>', ''));

        return $media;
    }
}
