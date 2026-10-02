<?php

namespace App\Http\Controllers\API\Store;

use App\Http\Controllers\Controller;
use App\Models\OnlineStore\OnlineStoreHomeSection;
use App\Models\OnlineStore\OnlineStoreListing;
use App\Services\OnlineStore\BannerService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class StoreHomeController extends Controller
{
    public function index(BannerService $banners): JsonResponse
    {
        $sections = OnlineStoreHomeSection::query()->where('is_visible', true)
            ->with('items')->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn ($section) => $this->compose($section, $banners))->values();

        return response()->json(['data' => ['sections' => $sections]]);
    }

    private function compose(OnlineStoreHomeSection $section, BannerService $banners): array
    {
        $items = match (true) {
            $section->section_type === 'hero' => $banners->active()->map(fn ($banner) => $banner->toArray())->all(),
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
        return $section->items->filter(function ($item) {
            if ($item->target_type === 'category') {
                return DB::table('online_store_categories')->where('id', $item->target_id)->where('is_active', true)->exists();
            }
            if ($item->target_type === 'listing') {
                return $this->eligibleListings()->whereKey($item->target_id)->exists();
            }

            return false;
        })->map(fn ($item) => ['target_type' => $item->target_type, 'target_id' => $item->target_id, 'sort_order' => $item->sort_order])->values()->all();
    }

    private function automaticItems(OnlineStoreHomeSection $section): array
    {
        $config = $section->selection_config ?? [];
        $limit = min(50, max(1, (int) ($config['limit'] ?? 12)));
        if ($section->section_type === 'categories') {
            return DB::table('online_store_categories')->where('is_active', true)->orderBy('sort_order')->orderBy('id')->limit($limit)->get()->map(fn ($row) => ['target_type' => 'category', 'target_id' => $row->id])->all();
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

        return $query->limit($limit)->get(['id'])->map(fn ($listing) => ['target_type' => 'listing', 'target_id' => $listing->id])->all();
    }

    private function eligibleListings(): Builder
    {
        return OnlineStoreListing::query()->where('status', 'published')->where('readiness_state', 'complete')
            ->whereHas('product', fn ($query) => $query->whereNull('deleted_at'))
            ->whereHas('product')
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('online_store_category_listing as membership')
                ->join('online_store_categories as category', 'category.id', '=', 'membership.online_store_category_id')
                ->whereColumn('membership.online_store_listing_id', 'online_store_listings.id')->where('category.is_active', true));
    }
}
