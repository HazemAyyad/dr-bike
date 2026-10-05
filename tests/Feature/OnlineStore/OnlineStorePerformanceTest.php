<?php

namespace Tests\Feature\OnlineStore;

use App\Services\OnlineStore\OnlineStoreDashboardService;
use App\Services\OnlineStore\OnlineStoreReportService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OnlineStorePerformanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (filter_var(env('ONLINE_STORE_DUMP_DERIVED_SCHEMA_READY', false), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Fresh-schema tests cannot alter restored dump-derived state.');
        }
        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    public function test_ten_thousand_listing_dashboard_and_report_have_bounded_queries_and_two_second_p95(): void
    {
        $now = now();
        $baseId = 100_000_000;
        foreach (range(0, 19) as $chunk) {
            $products = [];
            $listings = [];
            foreach (range(1, 500) as $offset) {
                $index = ($chunk * 500) + $offset;
                $productId = $baseId + $index;
                $products[] = [
                    'id' => $productId, 'product_code' => (string) $productId,
                    'nameAr' => 'Performance '.$index, 'nameEng' => 'Performance '.$index,
                    'isShow' => true, 'normailPrice' => 100, 'wholesalePrice' => 80,
                    'stock' => $index % 5, 'created_at' => $now, 'updated_at' => $now,
                ];
                $listings[] = [
                    'product_id' => $productId, 'status' => 'published', 'readiness_state' => 'complete',
                    'is_featured' => false, 'is_new' => false, 'show_on_home' => false,
                    'show_as_offer' => false, 'sort_order' => $index,
                    'created_at' => $now, 'updated_at' => $now,
                ];
            }
            DB::table('products')->insert($products);
            DB::table('online_store_listings')->insert($listings);
        }

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        $durations = [];
        foreach (range(1, 20) as $_) {
            $start = hrtime(true);
            app(OnlineStoreDashboardService::class)->summary();
            app(OnlineStoreReportService::class)->report(['origin' => 'store']);
            $durations[] = (hrtime(true) - $start) / 1_000_000_000;
        }
        sort($durations);
        $p95 = $durations[(int) ceil(count($durations) * 0.95) - 1];

        $this->assertLessThanOrEqual(30, (int) ceil($queries / 20), 'Aggregate query budget regressed.');
        $this->assertLessThanOrEqual(2.0, $p95, '95th percentile dashboard/report response exceeded two seconds.');
    }
}
