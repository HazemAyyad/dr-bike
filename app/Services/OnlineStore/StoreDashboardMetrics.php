<?php

namespace App\Services\OnlineStore;

use App\Models\SalesOrder;
use Illuminate\Support\Facades\DB;

/** One round trip, one aggregate per source table, with no persisted counters. */
final class StoreDashboardMetrics
{
    public function read(array $tables): object
    {
        $sources = [
            'listing' => DB::table('online_store_listings')->selectRaw("COUNT(*) listing_count, SUM(status = 'published') published, SUM(status = 'draft') draft, SUM(status = 'hidden') hidden, SUM(readiness_state = 'incomplete') incomplete, SUM(view_count) product_views"),
            'orders' => SalesOrder::query()->selectRaw("SUM(origin = 'admin') admin_orders, SUM(origin = 'store') store_orders, SUM(CASE WHEN origin = 'store' THEN total ELSE 0 END) store_total, SUM(origin = 'store' AND (partner_type = 'customer' OR (partner_type IS NULL AND customer_id IS NOT NULL))) retail_orders, SUM(origin = 'store' AND partner_type = 'seller') wholesale_orders"),
            'promotion' => DB::table('online_store_promotions')->where('is_active', true)
                ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
                ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()))->selectRaw('COUNT(*) active_promotions'),
            'coupon' => DB::table('online_store_coupons')->selectRaw('SUM(is_active = 1) active_coupons'),
            'category' => DB::table('online_store_categories')->selectRaw('COUNT(*) categories, SUM(is_active = 1) active_categories'),
            'section' => DB::table('online_store_home_sections')->selectRaw('COUNT(*) sections, SUM(is_visible = 1) visible_sections, SUM(view_count) section_views'),
            'banner' => DB::table('online_store_banners')->selectRaw('COUNT(*) banners, SUM(is_active = 1) active_banners, SUM(click_count) banner_clicks'),
            'users' => DB::table('users')->whereRaw('LOWER(type) = ?', ['user'])->selectRaw('COUNT(*) registered_users'),
            'links' => DB::table('online_store_account_links')->where('status', 'active')->selectRaw("COUNT(DISTINCT user_id) linked_users, SUM(role = 'customer') active_customer_links, SUM(role = 'seller') active_seller_links"),
        ];
        if (in_array('online_store_coupon_redemptions', $tables, true)) {
            $sources['coupon_usage'] = DB::table('online_store_coupon_redemptions')->selectRaw("SUM(status = 'reserved') reserved_coupons, SUM(status = 'applied') applied_coupons, SUM(status IN ('reserved','applied')) coupon_uses, SUM(CASE WHEN status IN ('reserved','applied') THEN discount_amount ELSE 0 END) coupon_discount");
        }
        if (in_array('online_store_promotion_redemptions', $tables, true)) {
            $sources['promotion_usage'] = DB::table('online_store_promotion_redemptions')->selectRaw('COUNT(*) promotion_uses, SUM(discount_amount) promotion_discount');
        }
        if (in_array('online_store_reviews', $tables, true)) {
            $sources['reviews'] = DB::table('online_store_reviews')->selectRaw("COUNT(*) reviews_total, SUM(status = 'pending') reviews_pending, SUM(status = 'published') reviews_published, AVG(CASE WHEN status = 'published' THEN rating ELSE NULL END) reviews_average");
        }
        if (in_array('online_store_daily_metrics', $tables, true)) {
            $sources['metrics'] = DB::table('online_store_daily_metrics')->selectRaw('SUM(store_visits) visits');
        }
        if (in_array('online_store_daily_visitors', $tables, true)) {
            $sources['visitors'] = DB::table('online_store_daily_visitors')->selectRaw('COUNT(DISTINCT visitor_hash) unique_visitors');
        }
        $query = DB::query()->fromSub(array_shift($sources), 'listing_metrics')->select('*');
        foreach ($sources as $alias => $source) $query->crossJoinSub($source, $alias.'_metrics');

        return $query->first();
    }
}
