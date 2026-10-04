<?php

namespace Tests\Feature\OnlineStore;

use App\Models\AccountingJournalEntry;
use App\Models\SalesOrder;
use App\Models\SalesOrderDelivery;
use App\Models\SalesOrderSettlement;
use App\Models\User;
use App\Services\OnlineStore\OnlineStoreCheckoutService;
use App\Services\SalesDailySessionService;
use App\Services\SalesOrderFulfillmentService;
use App\Services\SalesOrderService;
use App\Services\SalesOrderShiplyTrackingService;
use App\Services\ShiplyService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\Support\OnlineStoreCreditFixture;
use Tests\TestCase;

class OnlineStoreEndToEndTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (filter_var(env('ONLINE_STORE_DUMP_DERIVED_SCHEMA_READY', false), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Fresh-schema tests cannot alter restored dump-derived state.');
        }
        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    public function test_cash_checkout_replay_and_cancellation_reconcile_order_stock_and_finance(): void
    {
        $fixture = OnlineStoreCreditFixture::create('customer', 125, 4);
        $payload = OnlineStoreCreditFixture::nativePayload(
            $fixture['listing']->id,
            'e2e-cash-customer',
            'cash',
            0,
        );

        $first = app(OnlineStoreCheckoutService::class)->checkout($fixture['actor'], $payload);
        $retry = app(OnlineStoreCheckoutService::class)->checkout($fixture['actor'], $payload);
        $order = $first['order']->fresh('items');

        $this->assertTrue($first['created']);
        $this->assertFalse($retry['created']);
        $this->assertSame($order->id, $retry['order']->id);
        $this->assertSame(SalesOrder::ORIGIN_STORE, $order->origin);
        $this->assertSame($fixture['actor']->id, $order->origin_user_id);
        $this->assertSame('e2e-cash-customer', $order->client_request_id);
        $this->assertSame($fixture['party']->id, $order->customer_id);
        $this->assertSame('customer', $order->partner_type);
        $this->assertSame($fixture['party']->id, $order->partner_id);
        $this->assertSame(125.0, (float) $order->total);
        $this->assertSame(0.0, (float) $order->payment_amount);
        $this->assertSame(1, $order->items->count());
        $this->assertSame(1, (int) $order->items->sole()->reserved_qty);
        $this->assertSame(4, (int) $fixture['product']->fresh()->stock);
        $this->assertDatabaseCount('sales_orders', 1);
        $this->assertDatabaseCount('sales_order_items', 1);
        $this->assertSame(1, DB::table('sales_order_status_logs')->where('sales_order_id', $order->id)->count());
        $this->assertSame(1, DB::table('admin_notifications')->where('related_type', 'sales_order')->where('related_id', $order->id)->count());
        $this->assertSame(0, SalesOrderSettlement::query()->where('sales_order_id', $order->id)->count());
        $this->assertSame(0, DB::table('debt_transactions')->where('source', 'sales_order')->where('source_id', $order->id)->count());

        app(SalesOrderService::class)->cancel($fixture['actor'], $order->id, 'E2E cash cancellation');

        $this->assertDatabaseHas('sales_orders', ['id' => $order->id, 'status' => 'canceled']);
        $this->assertDatabaseHas('sales_order_items', ['sales_order_id' => $order->id, 'reserved_qty' => 0]);
        $this->assertSame(4, (int) $fixture['product']->fresh()->stock);
        $this->assertSame(0, DB::table('debt_transactions')->where('source', 'sales_order')->where('source_id', $order->id)->whereNull('deleted_at')->count());
    }

    /** @dataProvider partyProvider */
    public function test_mixed_checkout_reconciles_customer_and_seller_financial_effects_once(string $role): void
    {
        $fixture = OnlineStoreCreditFixture::create($role);
        $this->openSalesOrderSession($fixture['admin']);
        $payload = OnlineStoreCreditFixture::nativePayload(
            $fixture['listing']->id,
            'e2e-mixed-'.$role,
            'mixed',
            500,
            $role,
        );

        $first = app(OnlineStoreCheckoutService::class)->checkout($fixture['actor'], $payload);
        $retry = app(OnlineStoreCheckoutService::class)->checkout($fixture['actor'], $payload);
        $order = $first['order']->fresh();
        $settlement = SalesOrderSettlement::query()->where('sales_order_id', $order->id)->sole();
        $debt = DB::table('debt_transactions')->where('source', 'sales_order')
            ->where('source_id', $order->id)->whereNull('deleted_at')->sole();

        $this->assertTrue($first['created']);
        $this->assertFalse($retry['created']);
        $this->assertSame($order->id, $retry['order']->id);
        $this->assertSame($role, $order->partner_type);
        $this->assertSame($fixture['party']->id, $order->partner_id);
        $this->assertSame($role === 'customer' ? $fixture['party']->id : null, $order->customer_id);
        $this->assertSame(2000.0, (float) $order->total);
        $this->assertSame(500.0, (float) $settlement->amount);
        $this->assertSame(1500.0, (float) $debt->amount);
        $this->assertSame($role === 'customer' ? $fixture['party']->id : null, $debt->customer_id);
        $this->assertSame($role === 'seller' ? $fixture['party']->id : null, $debt->seller_id);
        $this->assertSame(1, SalesOrderSettlement::query()->where('sales_order_id', $order->id)->count());
        $this->assertSame(1, DB::table('debt_transactions')->where('source', 'sales_order')->where('source_id', $order->id)->whereNull('deleted_at')->count());
        $this->assertSame(1, AccountingJournalEntry::query()->where('source_key', 'sales_order_settlement:'.$settlement->id)->count());
    }

    public function test_legacy_checkout_keeps_bounded_canonical_dedupe_without_native_request_identity(): void
    {
        $fixture = OnlineStoreCreditFixture::create('customer', 75, 3);
        $payload = [
            'userAddId' => $fixture['actor']->id,
            'address' => 'E2E legacy address',
            'total' => 1,
            'date' => now()->toIso8601String(),
            'details' => [[
                'itemId' => $fixture['product']->id,
                'quantity' => 1,
                'price' => 1,
            ]],
        ];

        $first = app(OnlineStoreCheckoutService::class)->legacyCheckout($fixture['actor'], $payload);
        $retry = app(OnlineStoreCheckoutService::class)->legacyCheckout($fixture['actor'], [
            ...$payload,
            'userAddId' => $fixture['actor']->id + 1000,
            'total' => 9999,
            'date' => now()->addMinute()->toIso8601String(),
        ]);

        $this->assertTrue($first['created']);
        $this->assertFalse($retry['created']);
        $this->assertSame($first['order']->id, $retry['order']->id);
        $this->assertSame(75.0, (float) $first['order']->total);
        $this->assertStringStartsWith('legacy:', (string) $first['order']->client_request_id);
        $this->assertDatabaseCount('sales_orders', 1);
        $this->assertDatabaseCount('online_store_legacy_checkout_attempts', 1);
    }

    public function test_return_restores_dispatched_stock_and_shiply_tracking_is_idempotent(): void
    {
        $fixture = OnlineStoreCreditFixture::create('customer', 100, 2);
        $this->openSalesOrderSession($fixture['admin']);
        $accepted = app(OnlineStoreCheckoutService::class)->checkout(
            $fixture['actor'],
            OnlineStoreCreditFixture::nativePayload($fixture['listing']->id, 'e2e-return', 'cash', 0),
        );
        $order = app(SalesOrderService::class)->confirm($fixture['admin'], $accepted['order']->id);
        $this->assertSame(1, (int) $fixture['product']->fresh()->stock);

        $order->forceFill(['status' => 'with_delivery'])->save();
        SalesOrderDelivery::query()->forceCreate([
            'sales_order_id' => $order->id,
            'delivery_company_name' => 'Shiply',
            'shiply_parcel_code' => 'E2E-PARCEL',
            'shiply_mode' => 'test',
        ]);
        $tracking = app(SalesOrderShiplyTrackingService::class);
        $firstEvent = $tracking->recordHandoverSubmitted($order, 'E2E-PARCEL', 'test');
        $sameEvent = $tracking->recordHandoverSubmitted($order, 'E2E-PARCEL', 'test');
        $this->assertSame($firstEvent->id, $sameEvent->id);

        $shiply = Mockery::mock(ShiplyService::class);
        $shiply->shouldReceive('cancelParcel')->once()->with('E2E-PARCEL', 'test');
        $this->app->instance(ShiplyService::class, $shiply);

        $returned = app(SalesOrderFulfillmentService::class)->markReturned(
            $fixture['admin'],
            $order->id,
            'E2E Shiply return',
        );

        $this->assertSame('returned', $returned->status);
        $this->assertNull($returned->stock_deducted_at);
        $this->assertSame(2, (int) $fixture['product']->fresh()->stock);
        $this->assertSame(1, DB::table('sales_order_shiply_events')
            ->where('sales_order_id', $order->id)
            ->where('parcel_code', 'E2E-PARCEL')
            ->where('parcel_status_id', (int) config('shiply.parcel_status.submitted', 2))
            ->count());
        $this->assertDatabaseHas('sales_order_status_logs', [
            'sales_order_id' => $order->id,
            'from_status' => 'with_delivery',
            'to_status' => 'returned',
        ]);
    }

    public static function partyProvider(): array
    {
        return [['customer'], ['seller']];
    }

    private function openSalesOrderSession(User $admin): void
    {
        app(SalesDailySessionService::class)->openSession(
            $admin,
            confirmOpeningVariance: true,
            sessionType: SalesDailySessionService::TYPE_SALES_ORDERS,
        );
    }
}
