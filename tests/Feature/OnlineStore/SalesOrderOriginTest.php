<?php

namespace Tests\Feature\OnlineStore;

use App\Http\Middleware\RefreshSanctumTokenExpiry;
use App\Models\SalesOrder;
use App\Services\OnlineStore\StoreIdentityService;
use App\Services\SalesOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Support\OnlineStoreFixtureFactory;
use Tests\TestCase;

class SalesOrderOriginTest extends TestCase
{
    use RefreshDatabase {
        refreshTestDatabase as baseRefreshTestDatabase;
    }

    protected function refreshTestDatabase(): void
    {
        if (filter_var(env('ONLINE_STORE_PREMIGRATED_SCHEMA_READY', false), FILTER_VALIDATE_BOOL)) {
            $this->beginDatabaseTransaction();

            return;
        }

        if (! filter_var(env('ONLINE_STORE_DUMP_DERIVED_SCHEMA_READY', false), FILTER_VALIDATE_BOOL)) {
            $this->baseRefreshTestDatabase();
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (filter_var(env('ONLINE_STORE_DUMP_DERIVED_SCHEMA_READY', false), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Fresh-schema tests cannot alter restored dump-derived state.');
        }
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
        $this->assertStringNotContainsString("where('serial_number'", $migration);
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

    public function test_legacy_sales_order_list_filters_and_serializes_explicit_origin_fields(): void
    {
        $admin = OnlineStoreFixtureFactory::createAuthenticatedAdminActor();
        $storeUser = OnlineStoreFixtureFactory::createStoreActor();
        $this->withoutMiddleware(RefreshSanctumTokenExpiry::class);
        SalesOrder::query()->forceCreate([
            'serial_number' => 'ADMIN-ORIGIN-LIST',
            'origin' => 'admin',
            'status' => 'unconfirmed',
            'subtotal' => 0,
            'discount' => 0,
            'calculated_total' => 0,
            'total' => 0,
        ]);
        $storeOrder = SalesOrder::query()->forceCreate([
            'serial_number' => 'STORE-ORIGIN-LIST',
            'origin' => 'store',
            'origin_user_id' => $storeUser->id,
            'client_request_id' => 'origin-list-request',
            'status' => 'unconfirmed',
            'subtotal' => 0,
            'discount' => 0,
            'calculated_total' => 0,
            'total' => 0,
        ]);

        $this->getJson('/api/sales/orders?origin=store')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonCount(1, 'sales_orders')
            ->assertJsonPath('sales_orders.0.id', $storeOrder->id)
            ->assertJsonPath('sales_orders.0.origin', 'store')
            ->assertJsonPath('sales_orders.0.origin_user_id', $storeUser->id)
            ->assertJsonPath('sales_orders.0.client_request_id', 'origin-list-request')
            ->assertJsonPath('status_counts.unconfirmed', 1);

        $this->getJson('/api/sales/orders?'.http_build_query([
            'origin' => 'admin',
            'search' => 'ADMIN-ORIGIN-LIST',
        ]))
            ->assertOk()
            ->assertJsonCount(1, 'sales_orders')
            ->assertJsonPath('sales_orders.0.origin', 'admin')
            ->assertJsonPath('sales_orders.0.origin_user_id', null)
            ->assertJsonPath('sales_orders.0.client_request_id', null)
            ->assertJsonPath('status_counts.unconfirmed', 1);

        $this->assertSame($admin->id, auth()->id());
    }

    public function test_legacy_sales_order_detail_serializes_explicit_origin_fields(): void
    {
        OnlineStoreFixtureFactory::createAuthenticatedAdminActor();
        $storeUser = OnlineStoreFixtureFactory::createStoreActor();
        $this->withoutMiddleware(RefreshSanctumTokenExpiry::class);
        $order = SalesOrder::query()->forceCreate([
            'serial_number' => 'STORE-ORIGIN-DETAIL',
            'origin' => 'store',
            'origin_user_id' => $storeUser->id,
            'client_request_id' => 'origin-detail-request',
            'status' => 'unconfirmed',
            'subtotal' => 0,
            'discount' => 0,
            'calculated_total' => 0,
            'total' => 0,
        ]);

        $this->getJson('/api/sales/order?sales_order_id='.$order->id)
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('sales_order.origin', 'store')
            ->assertJsonPath('sales_order.origin_user_id', $storeUser->id)
            ->assertJsonPath('sales_order.client_request_id', 'origin-detail-request');
    }

    public function test_invalid_origin_filter_preserves_the_legacy_validation_envelope_and_status_code(): void
    {
        OnlineStoreFixtureFactory::createAuthenticatedAdminActor();
        $this->withoutMiddleware(RefreshSanctumTokenExpiry::class);

        $this->getJson('/api/sales/orders?origin=unknown')
            ->assertOk()
            ->assertJsonPath('status', 'error')
            ->assertJsonStructure(['message', 'errors' => ['origin']]);
    }
}
