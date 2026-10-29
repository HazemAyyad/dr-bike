<?php

namespace Tests\Feature\OnlineStore;

use App\Models\SalesOrder;
use App\Services\OnlineStore\CouponService;
use App\Services\OnlineStore\OnlineStoreCheckoutService;
use App\Services\SalesOrderNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Tests\Support\OnlineStoreCreditFixture;
use Tests\TestCase;

class StoreCheckoutEffectsTest extends TestCase
{
    use RefreshDatabase {
        refreshTestDatabase as private refreshDisposableDatabase;
    }

    protected function refreshTestDatabase(): void
    {
        $this->requireDisposableDatabase();
        if (filter_var(env('ONLINE_STORE_PREMIGRATED_SCHEMA_READY', false), FILTER_VALIDATE_BOOL)) {
            $this->beginDatabaseTransaction();

            return;
        }
        $this->refreshDisposableDatabase();
    }

    public function test_coupon_and_stock_effects_exist_before_notification_and_roll_back_together(): void
    {
        $fixture = OnlineStoreCreditFixture::create('customer', 2000, 10, 100);
        app(CouponService::class)->save($fixture['admin'], [
            'code' => 'EFFECTS10', 'discount_type' => 'fixed', 'discount_value' => 10,
            'minimum_order' => 0, 'total_usage_limit' => 1, 'per_user_usage_limit' => 1,
            'eligible_account_type' => 'customer', 'applies_to' => 'retail',
            'scope' => 'global', 'is_active' => true, 'targets' => [],
        ]);
        $before = DB::table('sales_orders')->count();
        $notifications = Mockery::mock(SalesOrderNotificationService::class);
        $notifications->shouldReceive('notifyStatusChange')->once()->andReturnUsing(function (SalesOrder $order): void {
            $this->assertDatabaseHas('online_store_coupon_redemptions', ['sales_order_id' => $order->id, 'status' => 'reserved']);
            $this->assertGreaterThan(0, (int) $order->items()->sum('reserved_qty'));
            $this->assertDatabaseHas('sales_order_status_logs', ['sales_order_id' => $order->id]);
            throw new RuntimeException('rollback-after-effects');
        });
        $this->app->instance(SalesOrderNotificationService::class, $notifications);
        $payload = OnlineStoreCreditFixture::nativePayload($fixture['listing']->id, 'effects-rollback', 'cash', 0);
        $payload['coupon_code'] = 'EFFECTS10';
        try {
            app(OnlineStoreCheckoutService::class)->checkout($fixture['actor'], $payload);
            $this->fail('Expected notification failure');
        } catch (RuntimeException $exception) {
            $this->assertSame('rollback-after-effects', $exception->getMessage());
            $this->assertSame($before, DB::table('sales_orders')->count());
            $this->assertDatabaseCount('online_store_coupon_redemptions', 0);
        }
    }
}
