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
        $tables = Schema::getTableListing(schemaQualified: false);
        $metrics = app(StoreDashboardMetrics::class)->read($tables);
        $storeTotal = (float) $metrics->store_total;
        $storeCount = (int) $metrics->store_orders;

        return [
            'listings' => [
                'published' => (int) $metrics->published,
                'draft' => (int) $metrics->draft,
                'hidden' => (int) $metrics->hidden,
                'incomplete' => (int) $metrics->incomplete,
                'out_of_stock' => $this->availability->outOfStockPublishedCount(),
            ],
            'orders' => [
                'origins' => [
                    'admin' => (int) $metrics->admin_orders,
                    'store' => $storeCount,
                ],
                'store_count' => $storeCount,
                'store_total' => round($storeTotal, 2),
                'average_order_value' => $storeCount > 0 ? round($storeTotal / $storeCount, 2) : 0.0,
                'retail_count' => (int) $metrics->retail_orders,
                'wholesale_count' => (int) $metrics->wholesale_orders,
            ],
            'active_promotions' => (int) $metrics->active_promotions,
            'coupons' => [
                'active' => (int) $metrics->active_coupons,
                'reserved' => (int) ($metrics->reserved_coupons ?? 0),
                'applied' => (int) ($metrics->applied_coupons ?? 0),
            ],
            'pending_reviews' => in_array('online_store_reviews', $tables, true)
                ? ['available' => true, 'value' => (int) $metrics->reviews_pending]
                : ['available' => false, 'value' => null],
            'debt_activity' => $this->storeDebtActivity($tables),
            'analytics' => $this->analytics($days, $tables, $metrics),
            'generated_at' => now()->toISOString(),
        ];
    }

    private function analytics(int $days, array $tables, object $metrics): array
    {
        $to = now()->endOfDay();
        $from = now()->subDays($days - 1)->startOfDay();
        $hasMetrics = in_array('online_store_daily_metrics', $tables, true);
        $hasVisitors = in_array('online_store_daily_visitors', $tables, true);
        $hasPromotionUsage = in_array('online_store_promotion_redemptions', $tables, true);

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
        $couponRows = in_array('online_store_coupon_redemptions', $tables, true)
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

        return [
            'period_days' => $days,
            'period_from' => $from->toDateString(),
            'period_to' => $to->toDateString(),
            'audience' => [
                'visits' => (int) ($metrics->visits ?? 0),
                'unique_visitors' => (int) ($metrics->unique_visitors ?? 0),
                'registered_users' => (int) $metrics->registered_users,
                'linked_users' => (int) $metrics->linked_users,
                'active_customer_links' => (int) $metrics->active_customer_links,
                'active_seller_links' => (int) $metrics->active_seller_links,
            ],
            'engagement' => [
                'product_views' => (int) $metrics->product_views,
                'section_views' => (int) $metrics->section_views,
                'banner_clicks' => (int) $metrics->banner_clicks,
            ],
            'commerce' => [
                'orders' => (int) $metrics->store_orders,
                'revenue' => round((float) $metrics->store_total, 2),
                'average_order_value' => $metrics->store_orders > 0
                    ? round((float) $metrics->store_total / $metrics->store_orders, 2) : 0.0,
                'by_status' => (clone $storeOrders)->selectRaw('status, COUNT(*) aggregate')
                    ->groupBy('status')->pluck('aggregate', 'status')->map(fn ($value) => (int) $value)->all(),
            ],
            'discounts' => [
                'promotions' => [
                    'uses' => (int) ($metrics->promotion_uses ?? 0),
                    'discount_total' => round((float) ($metrics->promotion_discount ?? 0), 2),
                ],
                'coupons' => [
                    'uses' => (int) ($metrics->coupon_uses ?? 0),
                    'discount_total' => round((float) ($metrics->coupon_discount ?? 0), 2),
                ],
            ],
            'catalog' => [
                'categories' => (int) $metrics->categories,
                'active_categories' => (int) $metrics->active_categories,
                'sections' => (int) $metrics->sections,
                'visible_sections' => (int) $metrics->visible_sections,
                'banners' => (int) $metrics->banners,
                'active_banners' => (int) $metrics->active_banners,
            ],
            'reviews' => [
                'total' => (int) ($metrics->reviews_total ?? 0),
                'pending' => (int) ($metrics->reviews_pending ?? 0),
                'published' => (int) ($metrics->reviews_published ?? 0),
                'average_rating' => round((float) ($metrics->reviews_average ?? 0), 2),
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

    private function storeDebtActivity(array $tables): array
    {
        if (! in_array('debt_transactions', $tables, true)) {
            return ['available' => false, 'given' => null, 'taken' => null, 'net_exposure' => null];
        }

        $query = DB::table('debt_transactions')->whereNull('archived_at')->whereNull('deleted_at')
            ->where('source', 'sales_order')
            ->whereIn('source_id', SalesOrder::query()->where('origin', SalesOrder::ORIGIN_STORE)->select('id'));
        $totals = $query->selectRaw("SUM(CASE WHEN type = 'given' THEN amount ELSE 0 END) given_total, SUM(CASE WHEN type = 'taken' THEN amount ELSE 0 END) taken_total")->first();
        $given = round((float) $totals->given_total, 2);
        $taken = round((float) $totals->taken_total, 2);

        return ['available' => true, 'given' => $given, 'taken' => $taken, 'net_exposure' => max(0, round($given - $taken, 2))];
    }
}
