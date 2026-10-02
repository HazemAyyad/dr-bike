<?php

namespace Tests\Feature\OnlineStore;

use App\Models\NormalImageProduct;
use App\Models\OnlineStore\OnlineStoreLegacyCheckoutAttempt;
use App\Models\OnlineStore\OnlineStoreListing;
use App\Models\SalesOrder;
use App\Services\OnlineStore\LegacyCheckoutDeduplicationService;
use App\Services\OnlineStore\OnlineStoreCheckoutService;
use App\Services\OnlineStore\StoreIdentityService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\OnlineStoreFixtureFactory;
use Tests\TestCase;

class LegacyCheckoutCompatibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (filter_var(env('ONLINE_STORE_DUMP_DERIVED_SCHEMA_READY', false), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Fresh-schema tests cannot alter restored dump-derived state.');
        } Artisan::call('migrate:fresh', ['--force' => true]);
    }

    public function test_fingerprint_ignores_client_prices_totals_timestamps_and_identity(): void
    {
        $actor = OnlineStoreFixtureFactory::createStoreActor();
        $service = app(LegacyCheckoutDeduplicationService::class);
        $base = ['details' => [['itemId' => 4, 'quantity' => 2, 'itemPrice' => 1]], 'address' => ' Main  Street ', 'cityId' => 7, 'discoundCode' => ' save ', 'userAddId' => 999, 'dateAdd' => 'yesterday', 'totalPriceWithOutDiscound' => 2];
        $changed = $base;
        $changed['details'][0]['itemPrice'] = 9999;
        $changed['totalPriceWithOutDiscound'] = 9999;
        $changed['userAddId'] = 12;
        $changed['dateAdd'] = 'today';
        $this->assertSame($service->fingerprint($actor, $base), $service->fingerprint($actor, $changed));

        $split = $base;
        $split['details'] = [
            ['itemId' => 4, 'quantity' => 1, 'itemPrice' => 4],
            ['itemId' => 4, 'quantity' => 1, 'itemPrice' => 8],
        ];
        $this->assertSame($service->fingerprint($actor, $base), $service->fingerprint($actor, $split));
    }

    public function test_two_minute_match_replays_then_expiry_allows_intentional_repeat(): void
    {
        $actor = OnlineStoreFixtureFactory::createStoreActor();
        $service = app(LegacyCheckoutDeduplicationService::class);
        $payload = ['details' => [['itemId' => 4, 'quantity' => 1]], 'address' => 'A'];
        $n = 0;
        $create = function ($id) use ($actor, &$n) {
            $n++;

            return SalesOrder::query()->forceCreate(['serial_number' => 'LEG-'.$n, 'origin' => 'store', 'origin_user_id' => $actor->id, 'client_request_id' => $id, 'status' => 'unconfirmed', 'subtotal' => 0, 'discount' => 0, 'calculated_total' => 0, 'total' => 0]);
        };
        $first = $service->execute($actor, $payload, $create);
        $retry = $service->execute($actor, $payload, $create);
        $this->assertSame($first['order']->id, $retry['order']->id);
        $this->assertSame(1, $n);
        OnlineStoreLegacyCheckoutAttempt::query()->update(['expires_at' => now()->subSecond()]);
        $again = $service->execute($actor, $payload, $create);
        $this->assertNotSame($first['order']->id, $again['order']->id);
        $this->assertSame(2, $n);
    }

    public function test_exact_legacy_shape_is_server_priced_and_request_identity_is_not_authoritative(): void
    {
        $admin = OnlineStoreFixtureFactory::createAdminActor();
        $actor = OnlineStoreFixtureFactory::createStoreActor();
        $customer = OnlineStoreFixtureFactory::createCustomer(['name' => 'Authoritative Party']);
        app(StoreIdentityService::class)->save($admin, $actor, ['role' => 'customer', 'customer_id' => $customer->id, 'account_source' => 'admin_app', 'status' => 'active']);
        $product = OnlineStoreFixtureFactory::createProduct(['descriptionEng' => 'Legacy product', 'normailPrice' => 100, 'stock' => 5]);
        $listing = OnlineStoreListing::query()->forceCreate(['product_id' => $product->id, 'status' => 'published', 'readiness_state' => 'complete']);
        $image = NormalImageProduct::query()->forceCreate(['id' => 1800000301, 'itemId' => $product->id, 'imageUrl' => 'fixtures/legacy.jpg']);
        DB::table('online_store_categories')->insert(['id' => 1800000302, 'name_translations' => json_encode(['en' => 'Legacy']), 'is_active' => true, 'show_on_home' => false, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('online_store_category_listing')->insert(['online_store_category_id' => 1800000302, 'online_store_listing_id' => $listing->id, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('online_store_media_presentations')->insert(['online_store_listing_id' => $listing->id, 'source_type' => 'normal_image', 'source_id' => $image->id, 'is_main' => true, 'is_visible' => true, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $payload = ['customerName' => 'Spoofed', 'userAddId' => 999999, 'address' => 'Delivery', 'totalPriceWithOutDiscound' => 2, 'totalPriceWithDiscound' => 2,
            'details' => [['itemId' => $product->id, 'itemSizeId' => null, 'itemSizeColorId' => null, 'quantity' => 2, 'itemPrice' => 1, 'totalPriceWithDiscound' => 2, 'totalPriceWithOutDiscound' => 2]]];

        $first = app(OnlineStoreCheckoutService::class)->legacyCheckout($actor, $payload);
        $payload['totalPriceWithOutDiscound'] = 9999;
        $payload['details'][0]['itemPrice'] = 9999;
        $retry = app(OnlineStoreCheckoutService::class)->legacyCheckout($actor, $payload);

        $order = $first['order']->fresh('items');
        $this->assertSame($order->id, $retry['order']->id);
        $this->assertSame($actor->id, (int) $order->origin_user_id);
        $this->assertSame($actor->id, (int) $order->created_by);
        $this->assertSame($customer->id, (int) $order->customer_id);
        $this->assertSame('Authoritative Party', $order->customer_name);
        $this->assertSame(200.0, (float) $order->subtotal);
        $this->assertSame(100.0, (float) $order->items->sole()->unit_price);
    }
}
