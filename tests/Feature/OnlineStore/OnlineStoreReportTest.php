<?php

namespace Tests\Feature\OnlineStore;

use App\Models\OnlineStore\OnlineStoreListing;
use App\Models\SalesOrder;
use App\Services\OnlineStore\OnlineStoreDashboardService;
use App\Services\OnlineStore\OnlineStoreReportService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\OnlineStoreFixtureFactory;
use Tests\TestCase;

class OnlineStoreReportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (filter_var(env('ONLINE_STORE_DUMP_DERIVED_SCHEMA_READY', false), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Fresh-schema tests cannot alter restored dump-derived state.');
        }
        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    public function test_dashboard_and_reports_reconcile_authoritative_origins_filters_and_review_fixture(): void
    {
        $actor = OnlineStoreFixtureFactory::createStoreActor();
        $customer = OnlineStoreFixtureFactory::createCustomer();
        $product = OnlineStoreFixtureFactory::createProduct(['stock' => 0]);
        $listing = OnlineStoreListing::query()->forceCreate(['product_id' => $product->id, 'status' => 'published', 'readiness_state' => 'complete']);
        $storeOrder = $this->order('store', 2000, 'customer', $customer->id, $actor->id);
        $this->order('admin', 500, null, null, null);
        DB::table('sales_order_items')->insert([
            'sales_order_id' => $storeOrder->id,
            'product_id' => $product->id,
            'product_name' => 'Report fixture',
            'quantity' => 1,
            'unit_price' => 2000,
            'line_total' => 2000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('online_store_reviews')->insert([
            'product_id' => $product->id,
            'customer_id' => $customer->id,
            'user_id' => $actor->id,
            'sales_order_id' => $storeOrder->id,
            'rating' => 5,
            'status' => 'pending',
            'is_verified_purchase' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('debt_transactions')->insert([
            'customer_id' => $customer->id,
            'seller_id' => null,
            'type' => 'given',
            'amount' => 1500,
            'currency' => 'شيكل',
            'balance_after' => 1500,
            'note' => 'Store report fixture',
            'transaction_date' => now()->toDateString(),
            'source' => 'sales_order',
            'source_id' => $storeOrder->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $dashboard = app(OnlineStoreDashboardService::class)->summary();
        $this->assertSame(1, $dashboard['orders']['origins']['admin']);
        $this->assertSame(1, $dashboard['orders']['origins']['store']);
        $this->assertSame(1, $dashboard['listings']['out_of_stock']);
        $this->assertSame(1, $dashboard['pending_reviews']['value']);
        $this->assertSame(1500.0, $dashboard['debt_activity']['net_exposure']);

        $report = app(OnlineStoreReportService::class)->report([
            'from' => now()->toDateString(),
            'to' => now()->toDateString(),
            'timezone' => config('app.timezone'),
            'origin' => 'store',
            'customer_id' => $customer->id,
            'listing_id' => $listing->id,
        ]);
        $this->assertSame(1, $report['orders']['count']);
        $this->assertSame(2000.0, $report['orders']['total']);
        $this->assertSame(2000.0, $report['orders']['average_order_value']);
        $this->assertSame(1, $report['orders']['origins']['store']);
        $this->assertSame(0, $report['orders']['origins']['admin']);
        $this->assertSame(1, $report['reviews']['pending']);
        $this->assertSame(1500.0, $report['debt_activity']['net_exposure']);
        $this->assertSame($listing->id, $report['best_selling'][0]['listing_id']);
        $this->assertSame(1, $report['best_selling'][0]['quantity']);
    }

    public function test_unavailable_optional_review_evidence_is_null_not_zero(): void
    {
        Schema::drop('online_store_reviews');
        $report = app(OnlineStoreReportService::class)->report([]);

        $this->assertFalse($report['reviews']['available']);
        $this->assertNull($report['reviews']['pending']);
        $this->assertNull($report['reviews']['published']);
    }

    private function order(string $origin, float $total, ?string $partyType, ?int $partyId, ?int $originUserId): SalesOrder
    {
        return SalesOrder::query()->forceCreate([
            'serial_number' => strtoupper($origin).'-'.uniqid(),
            'origin' => $origin,
            'origin_user_id' => $originUserId,
            'client_request_id' => $origin === 'store' ? 'report-'.uniqid() : null,
            'partner_type' => $partyType,
            'partner_id' => $partyId,
            'customer_id' => $partyType === 'customer' ? $partyId : null,
            'status' => 'unconfirmed',
            'subtotal' => $total,
            'discount' => 0,
            'calculated_total' => $total,
            'total' => $total,
            'payment_type' => 'credit',
            'payment_amount' => 0,
        ]);
    }
}
