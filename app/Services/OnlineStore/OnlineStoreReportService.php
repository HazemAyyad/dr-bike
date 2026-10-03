<?php

namespace App\Services\OnlineStore;

use App\Models\SalesOrder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class OnlineStoreReportService
{
    public function __construct(private readonly OnlineStoreAvailabilityService $availability) {}

    public function report(array $filters): array
    {
        $timezone = (string) ($filters['timezone'] ?? config('app.timezone', 'Asia/Jerusalem'));
        [$from, $to] = $this->range($filters, $timezone);
        $orders = SalesOrder::query();
        $this->applyOrderFilters($orders, $filters, $from, $to);
        $count = (clone $orders)->count();
        $total = round((float) (clone $orders)->sum('total'), 2);
        $originCounts = (clone $orders)->selectRaw('origin, COUNT(*) aggregate')->groupBy('origin')->pluck('aggregate', 'origin')->map(fn ($value) => (int) $value)->all();
        $priceContexts = [
            'retail' => (clone $orders)->where(fn ($query) => $query->where('partner_type', 'customer')
                ->orWhere(fn ($legacy) => $legacy->whereNull('partner_type')->whereNotNull('customer_id')))->count(),
            'wholesale' => (clone $orders)->where('partner_type', 'seller')->count(),
        ];

        $listingQuery = DB::table('online_store_listings as listing')->join('products as product', 'product.id', '=', 'listing.product_id');
        if (! empty($filters['listing_id'])) {
            $listingQuery->where('listing.id', (int) $filters['listing_id']);
        }
        if (! empty($filters['category_id'])) {
            $listingQuery->whereExists(function ($query) use ($filters) {
                $query->selectRaw('1')->from('online_store_category_listing as membership')
                    ->whereColumn('membership.online_store_listing_id', 'listing.id')
                    ->where('membership.online_store_category_id', (int) $filters['category_id']);
            });
        }

        return [
            'filters' => array_merge($filters, ['timezone' => $timezone, 'from_utc' => $from?->toISOString(), 'to_utc' => $to?->toISOString()]),
            'orders' => [
                'count' => $count,
                'total' => $total,
                'average_order_value' => $count > 0 ? round($total / $count, 2) : 0.0,
                'origins' => ['admin' => (int) ($originCounts['admin'] ?? 0), 'store' => (int) ($originCounts['store'] ?? 0)],
                'price_contexts' => $priceContexts,
            ],
            'listings' => [
                'count' => (clone $listingQuery)->count(),
                'out_of_stock' => $this->availability->outOfStockPublishedCount($filters),
            ],
            'best_selling' => $this->bestSelling($orders),
            'coupons' => $this->couponMetrics($orders, $from, $to),
            'promotions' => [
                'active' => DB::table('online_store_promotions')->where('is_active', true)
                    ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
                    ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', now()))->count(),
                'usage' => ['available' => false, 'applied_orders' => null, 'discount_total' => null],
            ],
            'reviews' => $this->reviewMetrics($from, $to),
            'debt_activity' => $this->debtMetrics($orders, $from, $to),
            'generated_at' => now()->toISOString(),
        ];
    }

    private function applyOrderFilters($query, array $filters, ?CarbonImmutable $from, ?CarbonImmutable $to): void
    {
        if ($from) {
            $query->where('created_at', '>=', $from);
        }
        if ($to) {
            $query->where('created_at', '<=', $to);
        }
        if (! empty($filters['origin'])) {
            $query->where('origin', $filters['origin']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['account_type'])) {
            $query->where('partner_type', $filters['account_type']);
        }
        if (! empty($filters['price_context'])) {
            $query->where('partner_type', $filters['price_context'] === 'wholesale' ? 'seller' : 'customer');
        }
        if (! empty($filters['customer_id'])) {
            $query->where(fn ($q) => $q->where('customer_id', (int) $filters['customer_id'])
                ->orWhere(fn ($party) => $party->where('partner_type', 'customer')->where('partner_id', (int) $filters['customer_id'])));
        }
        if (! empty($filters['seller_id'])) {
            $query->where('partner_type', 'seller')->where('partner_id', (int) $filters['seller_id']);
        }
        if (! empty($filters['listing_id']) || ! empty($filters['category_id'])) {
            $query->whereHas('items', function ($items) use ($filters) {
                $items->whereExists(function ($listing) use ($filters) {
                    $listing->selectRaw('1')->from('online_store_listings as report_listing')
                        ->whereColumn('report_listing.product_id', 'sales_order_items.product_id');
                    if (! empty($filters['listing_id'])) {
                        $listing->where('report_listing.id', (int) $filters['listing_id']);
                    }
                    if (! empty($filters['category_id'])) {
                        $listing->whereExists(function ($membership) use ($filters) {
                            $membership->selectRaw('1')->from('online_store_category_listing as report_membership')
                                ->whereColumn('report_membership.online_store_listing_id', 'report_listing.id')
                                ->where('report_membership.online_store_category_id', (int) $filters['category_id']);
                        });
                    }
                });
            });
        }
    }

    private function couponMetrics($orders, ?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        $query = DB::table('online_store_coupon_redemptions')->whereIn('status', ['reserved', 'applied'])
            ->whereIn('sales_order_id', (clone $orders)->select('id'));
        $this->applyRange($query, $from, $to);

        return ['uses' => (clone $query)->count(), 'discount_total' => round((float) (clone $query)->sum('discount_amount'), 2)];
    }

    private function bestSelling($orders): array
    {
        return DB::table('sales_order_items as item')
            ->join('online_store_listings as listing', 'listing.product_id', '=', 'item.product_id')
            ->where('item.is_hidden', false)
            ->whereIn('item.sales_order_id', (clone $orders)->select('id'))
            ->selectRaw('listing.id AS listing_id, item.product_id, SUM(item.quantity) AS quantity, SUM(item.line_total) AS revenue')
            ->groupBy('listing.id', 'item.product_id')
            ->orderByDesc('quantity')->orderBy('listing.id')
            ->limit(20)->get()->map(fn ($row) => [
                'listing_id' => (int) $row->listing_id,
                'product_id' => (int) $row->product_id,
                'quantity' => (int) $row->quantity,
                'revenue' => round((float) $row->revenue, 2),
            ])->all();
    }

    private function reviewMetrics(?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        if (! Schema::hasTable('online_store_reviews')) {
            return ['available' => false, 'pending' => null, 'published' => null];
        }
        $query = DB::table('online_store_reviews');
        $this->applyRange($query, $from, $to);

        return ['available' => true, 'pending' => (clone $query)->where('status', 'pending')->count(), 'published' => (clone $query)->where('status', 'published')->count()];
    }

    private function debtMetrics($orders, ?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        if (! Schema::hasTable('debt_transactions')) {
            return ['available' => false, 'given' => null, 'taken' => null, 'net_exposure' => null];
        }
        $orderIds = (clone $orders)->select('id');
        $query = DB::table('debt_transactions')->whereNull('archived_at')->whereNull('deleted_at')
            ->where('source', 'sales_order')->whereIn('source_id', $orderIds);
        if ($from) {
            $query->where('transaction_date', '>=', $from->toDateString());
        }
        if ($to) {
            $query->where('transaction_date', '<=', $to->toDateString());
        }
        $given = round((float) (clone $query)->where('type', 'given')->sum('amount'), 2);
        $taken = round((float) (clone $query)->where('type', 'taken')->sum('amount'), 2);

        return ['available' => true, 'given' => $given, 'taken' => $taken, 'net_exposure' => max(0, round($given - $taken, 2))];
    }

    private function range(array $filters, string $timezone): array
    {
        $from = ! empty($filters['from']) ? CarbonImmutable::parse($filters['from'], $timezone)->startOfDay()->utc() : null;
        $to = ! empty($filters['to']) ? CarbonImmutable::parse($filters['to'], $timezone)->endOfDay()->utc() : null;

        return [$from, $to];
    }

    private function applyRange(Builder $query, ?CarbonImmutable $from, ?CarbonImmutable $to): void
    {
        if ($from) {
            $query->where('created_at', '>=', $from);
        }
        if ($to) {
            $query->where('created_at', '<=', $to);
        }
    }
}
