<?php

namespace Tests\Feature\OnlineStore;

use App\Models\SalesOrder;
use App\Services\OnlineStore\StoreIdentityService;
use App\Services\SalesOrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\OnlineStoreFixtureFactory;
use Tests\TestCase;

class SalesOrderOriginTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (filter_var(env('ONLINE_STORE_DUMP_DERIVED_SCHEMA_READY', false), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Fresh-schema tests cannot alter restored dump-derived state.');
        } Artisan::call('migrate:fresh', ['--force' => true]);
    }

    public function test_default_and_explicit_origins_do_not_depend_on_serial_format(): void
    {
        $adminOrder = SalesOrder::query()->forceCreate(['serial_number' => 'STORE-LIKE-SERIAL', 'status' => 'unconfirmed', 'subtotal' => 0, 'discount' => 0, 'calculated_total' => 0, 'total' => 0]);
        $storeOrder = SalesOrder::query()->forceCreate(['serial_number' => 'ADMIN-LIKE-SERIAL', 'origin' => 'store', 'origin_user_id' => OnlineStoreFixtureFactory::createStoreActor()->id, 'client_request_id' => 'origin-test', 'status' => 'unconfirmed', 'subtotal' => 0, 'discount' => 0, 'calculated_total' => 0, 'total' => 0]);
        $this->assertSame('admin', $adminOrder->fresh()->origin);
        $this->assertSame('store', $storeOrder->fresh()->origin);
    }

    public function test_origin_migration_backfills_every_historical_null_as_admin(): void
    {
        $migration = file_get_contents(database_path('migrations/2026_10_01_000001_add_online_store_origin_to_sales_orders.php'));
        $this->assertStringContainsString("whereNull('origin')->update(['origin' => 'admin'])", $migration);
        $this->assertStringNotContainsString('serial_number', $migration);
    }

    public function test_existing_admin_creation_path_persists_admin_origin_without_request_id(): void
    {
        $admin = OnlineStoreFixtureFactory::createAdminActor();
        $product = OnlineStoreFixtureFactory::createProduct(['stock' => 2]);
        $order = app(SalesOrderService::class)->store($admin, new Request([
            'customer_name' => 'Admin order', 'payment_type' => 'cash',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100]],
        ]));
        $this->assertSame('admin', $order->origin);
        $this->assertNull($order->origin_user_id);
        $this->assertNull($order->client_request_id);
    }

    public function test_linked_history_spans_admin_and_store_origins(): void
    {
        $admin = OnlineStoreFixtureFactory::createAdminActor();
        $user = OnlineStoreFixtureFactory::createStoreActor();
        $customer = OnlineStoreFixtureFactory::createCustomer();
        app(StoreIdentityService::class)->save($admin, $user, ['role' => 'customer', 'customer_id' => $customer->id, 'account_source' => 'admin_app', 'status' => 'active']);
        foreach (['admin', 'store'] as $origin) {
            SalesOrder::query()->forceCreate(['serial_number' => strtoupper($origin), 'origin' => $origin, 'origin_user_id' => $origin === 'store' ? $user->id : null, 'client_request_id' => $origin === 'store' ? 'history' : null, 'customer_id' => $customer->id, 'status' => 'unconfirmed', 'subtotal' => 0, 'discount' => 0, 'calculated_total' => 0, 'total' => 0]);
        }
        $this->assertSame(['admin', 'store'], app(StoreIdentityService::class)->ownedOrders($user)->orderBy('id')->pluck('origin')->all());
    }
}
