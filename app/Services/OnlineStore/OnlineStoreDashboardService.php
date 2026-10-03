<?php

namespace App\Services\OnlineStore;

use App\Models\OnlineStore\OnlineStoreListing;
use App\Models\OnlineStore\OnlineStorePromotion;
use App\Models\SalesOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class OnlineStoreDashboardService
{
    public function __construct(private readonly OnlineStoreAvailabilityService $availability) {}

    public function summary(): array
    {
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
            'generated_at' => now()->toISOString(),
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
