<?php

namespace Tests\Feature\OnlineStore;

use App\Models\NormalImageProduct;
use App\Models\OnlineStore\OnlineStoreAccountLink;
use App\Models\OnlineStore\OnlineStoreListing;
use App\Models\SalesOrder;
use App\Services\OnlineStore\CouponService;
use App\Services\OnlineStore\OnlineStoreCheckoutService;
use App\Services\OnlineStore\StoreIdentityService;
use App\Services\SalesOrderService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\OnlineStoreFixtureFactory;
use Tests\TestCase;

class CouponRedemptionConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (filter_var(env('ONLINE_STORE_DUMP_DERIVED_SCHEMA_READY', false), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Fresh-schema tests cannot alter restored dump-derived state.');
        } Artisan::call('migrate:fresh', ['--force' => true]);
    }

    public function test_last_use_is_reserved_once_and_released_on_cancellation_policy(): void
    {
        $admin = OnlineStoreFixtureFactory::createAdminActor();
        $user = OnlineStoreFixtureFactory::createStoreActor();
        $customer = OnlineStoreFixtureFactory::createCustomer();
        $link = OnlineStoreAccountLink::query()->forceCreate(['user_id' => $user->id, 'customer_id' => $customer->id, 'role' => 'customer', 'account_source' => 'admin_app', 'status' => 'active', 'verified_at' => now()]);
        $coupons = app(CouponService::class);
        $coupon = $coupons->save($admin, ['code' => 'LAST', 'discount_type' => 'fixed', 'discount_value' => 5, 'minimum_order' => 0, 'total_usage_limit' => 1, 'eligible_account_type' => 'both', 'applies_to' => 'both', 'scope' => 'global', 'is_active' => true, 'targets' => []]);
        $order = SalesOrder::query()->forceCreate(['serial_number' => 'COUPON', 'origin' => 'store', 'origin_user_id' => $user->id, 'client_request_id' => 'coupon', 'customer_id' => $customer->id, 'status' => 'unconfirmed', 'subtotal' => 20, 'discount' => 5, 'calculated_total' => 15, 'total' => 15]);
        $locked = $coupons->findEligible('last', $user, $link, [], 20, 'retail', now(), true);
        $first = $coupons->reserve($locked, $order, $user, $link, 5);
        $same = $coupons->reserve($locked, $order, $user, $link, 5);
        $this->assertSame($first->id, $same->id);
        $this->assertDatabaseCount('online_store_coupon_redemptions', 1);
        $coupons->markApplied($order);
        $this->assertSame('applied', $first->fresh()->status);
        $coupons->release($order);
        $this->assertSame('released', $first->fresh()->status);
        $this->assertNotNull($first->fresh()->released_at);
    }

    public function test_reserved_use_counts_against_limit_until_released(): void
    {
        $admin = OnlineStoreFixtureFactory::createAdminActor();
        $firstUser = OnlineStoreFixtureFactory::createStoreActor();
        $secondUser = OnlineStoreFixtureFactory::createStoreActor();
        $customer = OnlineStoreFixtureFactory::createCustomer();
        $secondCustomer = OnlineStoreFixtureFactory::createCustomer();
        $firstLink = OnlineStoreAccountLink::query()->forceCreate(['user_id' => $firstUser->id, 'customer_id' => $customer->id, 'role' => 'customer', 'account_source' => 'admin_app', 'status' => 'active', 'verified_at' => now()]);
        $secondLink = OnlineStoreAccountLink::query()->forceCreate(['user_id' => $secondUser->id, 'customer_id' => $secondCustomer->id, 'role' => 'customer', 'account_source' => 'admin_app', 'status' => 'active', 'verified_at' => now()]);
        $service = app(CouponService::class);
        $coupon = $service->save($admin, ['code' => 'ONE', 'discount_type' => 'fixed', 'discount_value' => 1, 'minimum_order' => 0, 'total_usage_limit' => 1, 'eligible_account_type' => 'both', 'applies_to' => 'both', 'scope' => 'global', 'is_active' => true, 'targets' => []]);
        $order = SalesOrder::query()->forceCreate(['serial_number' => 'ONE', 'origin' => 'store', 'origin_user_id' => $firstUser->id, 'client_request_id' => 'one', 'customer_id' => $customer->id, 'status' => 'unconfirmed', 'subtotal' => 10, 'discount' => 1, 'calculated_total' => 9, 'total' => 9]);
        $service->reserve($coupon, $order, $firstUser, $firstLink, 1);
        $this->expectException(ValidationException::class);
        $service->findEligible('ONE', $secondUser, $secondLink, [], 10, 'retail', now(), true);
    }

    public function test_native_retry_creates_exactly_one_redemption_and_cancel_releases_it(): void
    {
        $admin = OnlineStoreFixtureFactory::createAdminActor();
        $actor = OnlineStoreFixtureFactory::createStoreActor();
        $customer = OnlineStoreFixtureFactory::createCustomer();
        app(StoreIdentityService::class)->save($admin, $actor, ['role' => 'customer', 'customer_id' => $customer->id, 'account_source' => 'admin_app', 'status' => 'active']);
        $product = OnlineStoreFixtureFactory::createProduct(['descriptionEng' => 'Coupon checkout', 'normailPrice' => 50, 'stock' => 3]);
        $listing = OnlineStoreListing::query()->forceCreate(['product_id' => $product->id, 'status' => 'published', 'readiness_state' => 'complete']);
        $image = NormalImageProduct::query()->forceCreate(['id' => 1800000401, 'itemId' => $product->id, 'imageUrl' => 'fixtures/coupon.jpg']);
        DB::table('online_store_categories')->insert(['id' => 1800000402, 'name_translations' => json_encode(['en' => 'Coupon']), 'is_active' => true, 'show_on_home' => false, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('online_store_category_listing')->insert(['online_store_category_id' => 1800000402, 'online_store_listing_id' => $listing->id, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('online_store_media_presentations')->insert(['online_store_listing_id' => $listing->id, 'source_type' => 'normal_image', 'source_id' => $image->id, 'is_main' => true, 'is_visible' => true, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
        app(CouponService::class)->save($admin, ['code' => 'CHECKOUT', 'discount_type' => 'fixed', 'discount_value' => 10, 'minimum_order' => 0, 'total_usage_limit' => 1, 'per_user_usage_limit' => 1, 'eligible_account_type' => 'customer', 'applies_to' => 'retail', 'scope' => 'global', 'is_active' => true, 'targets' => []]);
        $payload = ['client_request_id' => 'coupon-native', 'account_role' => 'customer', 'payment_type' => 'cash', 'coupon_code' => 'checkout', 'items' => [['listing_id' => $listing->id, 'quantity' => 1]]];

        $first = app(OnlineStoreCheckoutService::class)->checkout($actor, $payload);
        $retry = app(OnlineStoreCheckoutService::class)->checkout($actor, $payload);

        $this->assertSame($first['order']->id, $retry['order']->id);
        $this->assertDatabaseCount('online_store_coupon_redemptions', 1);
        $this->assertDatabaseHas('online_store_coupon_redemptions', ['sales_order_id' => $first['order']->id, 'status' => 'reserved', 'discount_amount' => 10]);
        app(SalesOrderService::class)->cancel($actor, $first['order']->id, 'fixture cancel');
        $this->assertDatabaseHas('online_store_coupon_redemptions', ['sales_order_id' => $first['order']->id, 'status' => 'released']);
        $this->assertDatabaseHas('sales_order_items', ['sales_order_id' => $first['order']->id, 'reserved_qty' => 0]);
    }

    public function test_failed_checkout_rolls_back_order_and_coupon_evidence(): void
    {
        $admin = OnlineStoreFixtureFactory::createAdminActor();
        $actor = OnlineStoreFixtureFactory::createStoreActor();
        $customer = OnlineStoreFixtureFactory::createCustomer();
        app(StoreIdentityService::class)->save($admin, $actor, ['role' => 'customer', 'customer_id' => $customer->id, 'account_source' => 'admin_app', 'status' => 'active']);
        $product = OnlineStoreFixtureFactory::createProduct(['descriptionEng' => 'No stock', 'stock' => 0]);
        $listing = OnlineStoreListing::query()->forceCreate(['product_id' => $product->id, 'status' => 'published', 'readiness_state' => 'complete']);

        try {
            app(OnlineStoreCheckoutService::class)->checkout($actor, ['client_request_id' => 'rollback', 'account_role' => 'customer', 'payment_type' => 'cash', 'items' => [['listing_id' => $listing->id, 'quantity' => 1]]]);
            $this->fail('Checkout should fail before accepting the order.');
        } catch (ValidationException) {
        }

        $this->assertDatabaseCount('sales_orders', 0);
        $this->assertDatabaseCount('online_store_coupon_redemptions', 0);
    }
}
