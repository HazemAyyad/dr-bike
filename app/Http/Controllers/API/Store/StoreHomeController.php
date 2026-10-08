<?php

namespace App\Http\Controllers\API\Store;

use App\Models\OnlineStore\OnlineStoreCategory;
use App\Models\OnlineStore\OnlineStoreHomeSection;
use App\Services\OnlineStore\BannerService;
use App\Services\OnlineStore\StorefrontCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class StoreHomeController extends StoreBaseController
{
    public function __construct(private readonly StorefrontCatalogService $catalog) {}

    public function index(BannerService $banners): JsonResponse
    {
        $sections = OnlineStoreHomeSection::query()->where('is_visible', true)
            ->with('items')->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn ($section) => $this->compose($section, $banners))->values();

        $viewedSectionIds = $sections
            ->filter(fn (array $section) => $section['section_type'] === 'maintenance' || count($section['items']) > 0)
            ->pluck('id');
        if ($viewedSectionIds->isNotEmpty()) {
            DB::table('online_store_home_sections')
                ->whereIn('id', $viewedSectionIds)
                ->increment('view_count');
        }

        return response()->json(['data' => ['sections' => $sections]]);
    }

    private function compose(OnlineStoreHomeSection $section, BannerService $banners): array
    {
        $items = match (true) {
            $section->section_type === 'hero' => $banners->active()->map(fn ($banner) => $this->bannerPayload($banner, $banners))->all(),
            $section->section_type === 'maintenance' => [$section->selection_config],
            $section->selection_mode === 'manual' => $this->manualItems($section),
            $section->selection_mode === 'automatic' => $this->automaticItems($section),
            default => [],
        };

        return ['id' => $section->id, 'key' => $section->key, 'section_type' => $section->section_type,
            'title_translations' => $section->title_translations, 'selection_mode' => $section->selection_mode,
            'selection_config' => $section->selection_config, 'items' => $items];
    }

    private function manualItems(OnlineStoreHomeSection $section): array
    {
        return $section->items->map(function ($item) {
            if ($item->target_type === 'category') {
                $category = OnlineStoreCategory::query()
                    ->whereKey($item->target_id)
                    ->where('is_active', true)
                    ->with('children')
                    ->first();
                if (! $category) {
                    return null;
                }

                return [
                    'target_type' => 'category',
                    'target_id' => $item->target_id,
                    'sort_order' => $item->sort_order,
                    'category' => $this->onlineStoreCategoryPayload($category),
                ];
            }
            if ($item->target_type === 'listing') {
                $listing = $this->eligibleListings()->with('product')->find($item->target_id);
                if (! $listing || ! $this->catalog->isEligible($listing)) {
                    return null;
                }

                return [
                    'target_type' => 'listing', 'target_id' => $item->target_id, 'sort_order' => $item->sort_order,
                    'listing' => $this->storefrontListingPayload($listing),
                ];
            }

            return null;
        })->filter()->values()->all();
    }

    private function automaticItems(OnlineStoreHomeSection $section): array
    {
        $config = $section->selection_config ?? [];
        $limit = min(50, max(1, (int) ($config['limit'] ?? 12)));
        if ($section->section_type === 'categories') {
            return OnlineStoreCategory::query()
                ->where('is_active', true)
                ->whereNull('parent_id')
                ->with('children')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->limit($limit)
                ->get()
                ->map(fn (OnlineStoreCategory $category) => [
                    'target_type' => 'category',
                    'target_id' => $category->id,
                    'category' => $this->onlineStoreCategoryPayload($category),
                ])->all();
        }
        $query = $this->eligibleListings();
        $selector = $config['selector'] ?? null;
        match ($selector) {
            'best_sellers' => $query->whereHas('product', fn ($q) => $q->where('isMoreSales', true))->orderBy('sort_order')->orderBy('id'),
            'recent' => $query->orderByDesc('created_at')->orderByDesc('id'),
            'offers' => $query->where('show_as_offer', true)->orderBy('sort_order')->orderBy('id'),
            'featured' => $query->where('is_featured', true)->orderBy('sort_order')->orderBy('id'),
            'new' => $query->where('is_new', true)->orderBy('sort_order')->orderBy('id'),
            'home' => $query->where('show_on_home', true)->orderBy('sort_order')->orderBy('id'),
            default => $query->whereRaw('1 = 0'),
        };

        return $this->catalog->eligible($query)->take($limit)->map(fn ($listing) => [
            'target_type' => 'listing', 'target_id' => $listing->id,
            'listing' => $this->storefrontListingPayload($listing),
        ])->all();
    }

    private function bannerPayload($banner, BannerService $banners): array
    {
        return [
            'id' => (int) $banner->id,
            'image_path' => $this->storefrontMediaPath($banner->image_path),
            'title_translations' => (array) $banner->title_translations,
            'content_translations' => (array) $banner->content_translations,
            'action_type' => (string) $banner->action_type,
            'action_target_id' => $banner->action_target_id === null ? null : (int) $banner->action_target_id,
            'action_product_id' => $banners->actionProductId($banner),
            'action_url' => $banner->action_url,
        ];
    }

    private function eligibleListings()
    {
        return $this->catalog->eligibleQuery();
    }
}
