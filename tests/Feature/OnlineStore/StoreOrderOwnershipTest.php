<?php

namespace Tests\Feature\OnlineStore;

use App\Models\SalesOrder;
use App\Models\SalesOrderDelivery;
use App\Models\SalesOrderShiplyEvent;
use App\Models\Store\StoreUser;
use App\Services\OnlineStore\StoreIdentityService;
use App\Services\SalesOrderService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\OnlineStoreFixtureFactory;
use Tests\TestCase;

class StoreOrderOwnershipTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (filter_var(env('ONLINE_STORE_DUMP_DERIVED_SCHEMA_READY', false), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Fresh-schema tests cannot alter restored dump-derived state.');
        } Artisan::call('migrate:fresh', ['--force' => true]);
    }

    public function test_cross_account_order_lookup_is_not_disclosed(): void
    {
        $admin = OnlineStoreFixtureFactory::createAdminActor();
        $owner = OnlineStoreFixtureFactory::createStoreActor();
        $other = OnlineStoreFixtureFactory::createStoreActor();
        $customer = OnlineStoreFixtureFactory::createCustomer();
        $otherCustomer = OnlineStoreFixtureFactory::createCustomer();
        $identity = app(StoreIdentityService::class);
        $identity->save($admin, $owner, ['role' => 'customer', 'customer_id' => $customer->id, 'account_source' => 'admin_app', 'status' => 'active']);
        $identity->save($admin, $other, ['role' => 'customer', 'customer_id' => $otherCustomer->id, 'account_source' => 'admin_app', 'status' => 'active']);
        $order = SalesOrder::query()->forceCreate(['serial_number' => 'OWNED', 'origin' => 'store', 'origin_user_id' => $owner->id, 'client_request_id' => 'owned', 'customer_id' => $customer->id, 'status' => 'unconfirmed', 'subtotal' => 0, 'discount' => 0, 'calculated_total' => 0, 'total' => 0]);
        $this->assertSame($order->id, $identity->ownedOrderOrFail($owner, $order->id)->id);
        $this->expectException(ModelNotFoundException::class);
        $identity->ownedOrderOrFail($other, $order->id);
    }

    public function test_legacy_cancel_delegates_to_authoritative_lifecycle_and_keeps_delivery_tracking_relations(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/API/Store/StoreOrdersController.php'));
        $this->assertStringContainsString('$orders->cancel(', $controller);
        $this->assertStringNotContainsString("'status' => 'canceled'", $controller);
        $this->assertStringContainsString("'latestHandover'", $controller);
        $this->assertStringContainsString("'shiplyEvents'", $controller);
        $this->assertStringContainsString("'statusLogs.user'", $controller);
    }

    public function test_legacy_history_rejects_a_different_submitted_user_id_without_disclosing_orders(): void
    {
        $actor = OnlineStoreFixtureFactory::createStoreActor();
        $token = StoreUser::query()->findOrFail($actor->id)->createToken('fixture-store')->plainTextToken;
        $this->withToken($token)->postJson('/Orders/GetAllOrdersByUserId?userId='.($actor->id + 1), [])->assertNotFound();
    }

    public function test_owned_cancel_uses_existing_status_log_and_preserves_delivery_and_shiply_evidence(): void
    {
        $admin = OnlineStoreFixtureFactory::createAdminActor();
        $actor = OnlineStoreFixtureFactory::createStoreActor();
        $customer = OnlineStoreFixtureFactory::createCustomer();
        $identity = app(StoreIdentityService::class);
        $identity->save($admin, $actor, ['role' => 'customer', 'customer_id' => $customer->id, 'account_source' => 'admin_app', 'status' => 'active']);
        $order = SalesOrder::query()->forceCreate(['serial_number' => 'CANCEL', 'origin' => 'store', 'origin_user_id' => $actor->id, 'client_request_id' => 'cancel', 'customer_id' => $customer->id, 'status' => 'unconfirmed', 'reserves_stock' => false, 'subtotal' => 0, 'discount' => 0, 'calculated_total' => 0, 'total' => 0]);
        SalesOrderDelivery::query()->forceCreate(['sales_order_id' => $order->id, 'delivery_company_name' => 'Fixture carrier']);
        SalesOrderShiplyEvent::query()->forceCreate(['sales_order_id' => $order->id, 'parcel_code' => 'FIXTURE', 'parcel_status_id' => 2, 'source' => 'fixture', 'occurred_at' => now()]);

        app(SalesOrderService::class)->cancel($actor, $identity->ownedOrderOrFail($actor, $order->id)->id, 'Store customer canceled the order');

        $this->assertDatabaseHas('sales_orders', ['id' => $order->id, 'status' => 'canceled']);
        $this->assertDatabaseHas('sales_order_status_logs', ['sales_order_id' => $order->id, 'from_status' => 'unconfirmed', 'to_status' => 'canceled', 'user_id' => $actor->id]);
        $this->assertDatabaseHas('sales_order_deliveries', ['sales_order_id' => $order->id]);
        $this->assertDatabaseHas('sales_order_shiply_events', ['sales_order_id' => $order->id, 'parcel_code' => 'FIXTURE']);
    }
}
