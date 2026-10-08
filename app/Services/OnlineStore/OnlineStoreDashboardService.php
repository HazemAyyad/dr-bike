<?php

namespace App\Services\OnlineStore;

use App\Models\OnlineStore\OnlineStoreListing;
use App\Models\OnlineStore\OnlineStorePromotion;
use App\Models\SalesOrder;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class OnlineStoreDashboardService
{
    public function __construct(private readonly OnlineStoreAvailabilityService $availability) {}

    public function summary(int $days = 30): array
    {
        $days = max(7, min(365, $days));
        $now = now();
        $listingStates = OnlineStoreListing::query()->selectRaw('status, COUNT(*) aggregate')->groupBy('status')->pluck('aggregate', 'status');
        $readinessStates = OnlineStoreListing::query()->selectRaw('readiness_state, COUNT(*) aggregate')->groupBy('readiness_state')->pluck('aggregate', 'readiness_state');
        $storeOrders = SalesOrder::query()->where('origin', SalesOrder::ORIGIN_STORE);
        $storeTotal = (float) (clone $storeOrders)->sum('total');
        $storeCount = (clone $storeOrders)->count();

        return [
            'listings' => [
                'published' => (int) ($listingStates['published'] ?? 0),
                'draft' => (int) ($listingStates['draft'] ?? 0),
                'hidden' => (int) ($listingStates['hidden'] ?? 0),
                'incomplete' => (int) ($readinessStates['incomplete'] ?? 0),
                'out_of_stock' => $this->availability->outOfStockPublishedCount(),
            ],
            'orders' => [
                'origins' => [
                    'admin' => SalesOrder::query()->where('origin', SalesOrder::ORIGIN_ADMIN)->count(),
                    'store' => $storeCount,
                ],
                'store_count' => $storeCount,
                'store_total' => round($storeTotal, 2),
                'average_order_value' => $storeCount > 0 ? round($storeTotal / $storeCount, 2) : 0.0,
                'retail_count' => (clone $storeOrders)->where(fn ($query) => $query->where('partner_type', 'customer')
                    ->orWhere(fn ($legacy) => $legacy->whereNull('partner_type')->whereNotNull('customer_id')))->count(),
                'wholesale_count' => (clone $storeOrders)->where('partner_type', 'seller')->count(),
            ],
            'active_promotions' => OnlineStorePromotion::query()->where('is_active', true)
                ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
                ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now))->count(),
            'coupons' => [
                'active' => DB::table('online_store_coupons')->where('is_active', true)->count(),
                'reserved' => DB::table('online_store_coupon_redemptions')->where('status', 'reserved')->count(),
                'applied' => DB::table('online_store_coupon_redemptions')->where('status', 'applied')->count(),
            ],
            'pending_reviews' => Schema::hasTable('online_store_reviews')
                ? ['available' => true, 'value' => DB::table('online_store_reviews')->where('status', 'pending')->count()]
                : ['available' => false, 'value' => null],
            'debt_activity' => $this->storeDebtActivity(),
            'analytics' => $this->analytics($days),
            'generated_at' => now()->toISOString(),
        ];
    }

    private function analytics(int $days): array
    {
        $to = now()->endOfDay();
        $from = now()->subDays($days - 1)->startOfDay();
        $hasMetrics = Schema::hasTable('online_store_daily_metrics');
        $hasVisitors = Schema::hasTable('online_store_daily_visitors');
        $hasPromotionUsage = Schema::hasTable('online_store_promotion_redemptions');

        $metricRows = $hasMetrics
            ? DB::table('online_store_daily_metrics')
                ->whereBetween('metric_date', [$from->toDateString(), $to->toDateString()])
                ->get()->keyBy(fn ($row) => (string) $row->metric_date)
            : collect();
        $visitorRows = $hasVisitors
            ? DB::table('online_store_daily_visitors')
                ->selectRaw('visit_date, COUNT(*) aggregate')
                ->whereBetween('visit_date', [$from->toDateString(), $to->toDateString()])
                ->groupBy('visit_date')->pluck('aggregate', 'visit_date')
            : collect();
        $orderRows = SalesOrder::query()
            ->where('origin', SalesOrder::ORIGIN_STORE)
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('DATE(created_at) metric_date, COUNT(*) orders_count, SUM(total) revenue')
            ->groupByRaw('DATE(created_at)')->get()->keyBy('metric_date');
        $couponRows = Schema::hasTable('online_store_coupon_redemptions')
            ? DB::table('online_store_coupon_redemptions')
                ->whereBetween('created_at', [$from, $to])
                ->whereIn('status', ['reserved', 'applied'])
                ->selectRaw('DATE(created_at) metric_date, COUNT(*) uses_count')
                ->groupByRaw('DATE(created_at)')->pluck('uses_count', 'metric_date')
            : collect();
        $promotionRows = $hasPromotionUsage
            ? DB::table('online_store_promotion_redemptions')
                ->whereBetween('used_at', [$from, $to])
                ->selectRaw('DATE(used_at) metric_date, COUNT(*) uses_count')
                ->groupByRaw('DATE(used_at)')->pluck('uses_count', 'metric_date')
            : collect();

        $daily = collect(CarbonPeriod::create($from->toDateString(), $to->toDateString()))
            ->map(function ($date) use ($metricRows, $visitorRows, $orderRows, $couponRows, $promotionRows) {
                $key = $date->toDateString();
                $metric = $metricRows->get($key);
                $orders = $orderRows->get($key);

                return [
                    'date' => $key,
                    'visits' => (int) ($metric->store_visits ?? 0),
                    'unique_visitors' => (int) ($visitorRows[$key] ?? 0),
                    'product_views' => (int) ($metric->product_views ?? 0),
                    'section_views' => (int) ($metric->section_views ?? 0),
                    'banner_clicks' => (int) ($metric->banner_clicks ?? 0),
                    'orders' => (int) ($orders->orders_count ?? 0),
                    'revenue' => round((float) ($orders->revenue ?? 0), 2),
                    'coupon_uses' => (int) ($couponRows[$key] ?? 0),
                    'promotion_uses' => (int) ($promotionRows[$key] ?? 0),
                ];
            })->values();

        $storeOrders = SalesOrder::query()->where('origin', SalesOrder::ORIGIN_STORE);
        $promotionUsage = $hasPromotionUsage ? DB::table('online_store_promotion_redemptions') : null;
        $couponUsage = Schema::hasTable('online_store_coupon_redemptions')
            ? DB::table('online_store_coupon_redemptions')->whereIn('status', ['reserved', 'applied'])
            : null;

        return [
            'period_days' => $days,
            'period_from' => $from->toDateString(),
            'period_to' => $to->toDateString(),
            'audience' => [
                'visits' => $hasMetrics ? (int) DB::table('online_store_daily_metrics')->sum('store_visits') : 0,
                'unique_visitors' => $hasVisitors ? (int) DB::table('online_store_daily_visitors')->distinct()->count('visitor_hash') : 0,
                'registered_users' => (int) DB::table('users')->whereRaw('LOWER(type) = ?', ['user'])->count(),
                'linked_users' => (int) DB::table('online_store_account_links')->where('status', 'active')->distinct()->count('user_id'),
                'active_customer_links' => (int) DB::table('online_store_account_links')->where('status', 'active')->where('role', 'customer')->count(),
                'active_seller_links' => (int) DB::table('online_store_account_links')->where('status', 'active')->where('role', 'seller')->count(),
            ],
            'engagement' => [
                'product_views' => (int) DB::table('online_store_listings')->sum('view_count'),
                'section_views' => (int) DB::table('online_store_home_sections')->sum('view_count'),
                'banner_clicks' => (int) DB::table('online_store_banners')->sum('click_count'),
            ],
            'commerce' => [
                'orders' => (clone $storeOrders)->count(),
                'revenue' => round((float) (clone $storeOrders)->sum('total'), 2),
                'average_order_value' => ($count = (clone $storeOrders)->count()) > 0
                    ? round((float) (clone $storeOrders)->sum('total') / $count, 2) : 0.0,
                'by_status' => (clone $storeOrders)->selectRaw('status, COUNT(*) aggregate')
                    ->groupBy('status')->pluck('aggregate', 'status')->map(fn ($value) => (int) $value)->all(),
            ],
            'discounts' => [
                'promotions' => [
                    'uses' => $promotionUsage ? (clone $promotionUsage)->count() : 0,
                    'discount_total' => $promotionUsage ? round((float) (clone $promotionUsage)->sum('discount_amount'), 2) : 0.0,
                ],
                'coupons' => [
                    'uses' => $couponUsage ? (clone $couponUsage)->count() : 0,
                    'discount_total' => $couponUsage ? round((float) (clone $couponUsage)->sum('discount_amount'), 2) : 0.0,
                ],
            ],
            'catalog' => [
                'categories' => (int) DB::table('online_store_categories')->count(),
                'active_categories' => (int) DB::table('online_store_categories')->where('is_active', true)->count(),
                'sections' => (int) DB::table('online_store_home_sections')->count(),
                'visible_sections' => (int) DB::table('online_store_home_sections')->where('is_visible', true)->count(),
                'banners' => (int) DB::table('online_store_banners')->count(),
                'active_banners' => (int) DB::table('online_store_banners')->where('is_active', true)->count(),
            ],
            'reviews' => [
                'total' => (int) DB::table('online_store_reviews')->count(),
                'pending' => (int) DB::table('online_store_reviews')->where('status', 'pending')->count(),
                'published' => (int) DB::table('online_store_reviews')->where('status', 'published')->count(),
                'average_rating' => round((float) DB::table('online_store_reviews')->where('status', 'published')->avg('rating'), 2),
            ],
            'top_products' => DB::table('online_store_listings as listing')
                ->join('products as product', 'product.id', '=', 'listing.product_id')
                ->select(['listing.id', 'listing.product_id', 'product.nameAr as name', 'listing.view_count'])
                ->orderByDesc('listing.view_count')->orderBy('listing.id')->limit(5)->get()->map(fn ($row) => (array) $row)->all(),
            'top_sections' => DB::table('online_store_home_sections')
                ->select(['id', 'title_translations', 'view_count'])
                ->orderByDesc('view_count')->orderBy('id')->limit(5)->get()->map(function ($row) {
                    $translations = json_decode((string) $row->title_translations, true) ?: [];
                    return ['id' => (int) $row->id, 'name' => $translations['ar'] ?? $translations['en'] ?? '#'.$row->id, 'view_count' => (int) $row->view_count];
                })->all(),
            'daily' => $daily->all(),
        ];
    }

    private function storeDebtActivity(): array
    {
        if (! Schema::hasTable('debt_transactions')) {
            return ['available' => false, 'given' => null, 'taken' => null, 'net_exposure' => null];
        }

        $query = DB::table('debt_transactions')->whereNull('archived_at')->whereNull('deleted_at')
            ->where('source', 'sales_order')
            ->whereIn('source_id', SalesOrder::query()->where('origin', SalesOrder::ORIGIN_STORE)->select('id'));
        $given = round((float) (clone $query)->where('type', 'given')->sum('amount'), 2);
        $taken = round((float) (clone $query)->where('type', 'taken')->sum('amount'), 2);

        return ['available' => true, 'given' => $given, 'taken' => $taken, 'net_exposure' => max(0, round($given - $taken, 2))];
    }
}
