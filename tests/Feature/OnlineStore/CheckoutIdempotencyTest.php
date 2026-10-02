<?php

namespace Tests\Feature\OnlineStore;

use App\Models\NormalImageProduct;
use App\Models\OnlineStore\OnlineStoreListing;
use App\Models\SalesOrder;
use App\Services\OnlineStore\OnlineStoreCheckoutService;
use App\Services\OnlineStore\StoreCheckoutIdempotencyService;
use App\Services\OnlineStore\StoreIdentityService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\OnlineStoreFixtureFactory;
use Tests\TestCase;

class CheckoutIdempotencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (filter_var(env('ONLINE_STORE_DUMP_DERIVED_SCHEMA_READY', false), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Fresh-schema tests cannot alter restored dump-derived state.');
        } Artisan::call('migrate:fresh', ['--force' => true]);
    }

    public function test_same_actor_retry_returns_one_order_and_cross_actor_same_id_is_isolated(): void
    {
        $service = app(StoreCheckoutIdempotencyService::class);
        $a = OnlineStoreFixtureFactory::createStoreActor();
        $b = OnlineStoreFixtureFactory::createStoreActor();
        $effects = 0;
        $create = function ($actor) use (&$effects) {
            return function () use ($actor, &$effects) {
                $effects++;

                return SalesOrder::query()->forceCreate(['serial_number' => 'IDEM-'.$actor->id, 'origin' => 'store', 'origin_user_id' => $actor->id, 'client_request_id' => 'same', 'status' => 'unconfirmed', 'subtotal' => 0, 'discount' => 0, 'calculated_total' => 0, 'total' => 0]);
            };
        };
        $first = $service->execute($a, 'same', $create($a));
        $retry = $service->execute($a, 'same', fn () => $this->fail('replay must be found before side effects'));
        $other = $service->execute($b, 'same', $create($b));
        $this->assertTrue($first['created']);
        $this->assertFalse($retry['created']);
        $this->assertTrue($other['created']);
        $this->assertSame($first['order']->id, $retry['order']->id);
        $this->assertSame(2, $effects);
        $this->assertDatabaseCount('sales_orders', 2);
    }

    public function test_checkout_replay_does_not_duplicate_items_reservation_status_log_or_notification(): void
    {
        $admin = OnlineStoreFixtureFactory::createAdminActor();
        $actor = OnlineStoreFixtureFactory::createStoreActor();
        $customer = OnlineStoreFixtureFactory::createCustomer();
        app(StoreIdentityService::class)->save($admin, $actor, ['role' => 'customer', 'customer_id' => $customer->id, 'account_source' => 'admin_app', 'status' => 'active']);
        $product = OnlineStoreFixtureFactory::createProduct(['descriptionEng' => 'Checkout product', 'normailPrice' => 125, 'stock' => 4]);
        $listing = OnlineStoreListing::query()->forceCreate(['product_id' => $product->id, 'status' => 'published', 'readiness_state' => 'complete']);
        $image = NormalImageProduct::query()->forceCreate(['id' => 1800000201, 'itemId' => $product->id, 'imageUrl' => 'fixtures/checkout.jpg']);
        DB::table('online_store_categories')->insert(['id' => 1800000202, 'name_translations' => json_encode(['en' => 'Checkout']), 'is_active' => true, 'show_on_home' => false, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('online_store_category_listing')->insert(['online_store_category_id' => 1800000202, 'online_store_listing_id' => $listing->id, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('online_store_media_presentations')->insert(['online_store_listing_id' => $listing->id, 'source_type' => 'normal_image', 'source_id' => $image->id, 'is_main' => true, 'is_visible' => true, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $payload = ['client_request_id' => 'native-retry', 'account_role' => 'customer', 'payment_type' => 'cash', 'items' => [['listing_id' => $listing->id, 'quantity' => 2]]];

        $first = app(OnlineStoreCheckoutService::class)->checkout($actor, $payload);
        $retry = app(OnlineStoreCheckoutService::class)->checkout($actor, $payload);

        $this->assertTrue($first['created']);
        $this->assertFalse($retry['created']);
        $this->assertSame($first['order']->id, $retry['order']->id);
        $this->assertDatabaseCount('sales_orders', 1);
        $this->assertDatabaseCount('sales_order_items', 1);
        $this->assertDatabaseHas('sales_order_items', ['sales_order_id' => $first['order']->id, 'quantity' => 2, 'reserved_qty' => 2, 'unit_price' => 125]);
        $this->assertSame(1, DB::table('sales_order_status_logs')->where('sales_order_id', $first['order']->id)->count());
        $this->assertSame(1, DB::table('admin_notifications')->where('related_type', 'sales_order')->where('related_id', $first['order']->id)->count());
    }
}
