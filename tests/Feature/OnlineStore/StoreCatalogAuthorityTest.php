<?php

namespace Tests\Feature\OnlineStore;

use App\Enums\SalesOrderStatus;
use App\Http\Resources\OnlineStore\StorefrontListingResource;
use App\Models\NormalImageProduct;
use App\Models\OnlineStore\OnlineStoreListing;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Services\OnlineStore\StoreAvailabilityService;
use App\Services\OnlineStore\StorePriceResolver;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\OnlineStoreFixtureFactory;
use Tests\TestCase;

class StoreCatalogAuthorityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (filter_var(env('ONLINE_STORE_DUMP_DERIVED_SCHEMA_READY', false), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Fresh-schema tests cannot alter restored dump-derived state.');
        }
        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    public function test_prices_and_mixed_variant_availability_are_authoritative_and_reservation_aware(): void
    {
        $product = OnlineStoreFixtureFactory::createProduct(['descriptionEng' => 'Description', 'normailPrice' => 100, 'wholesalePrice' => 70, 'stock' => 9]);
        $first = OnlineStoreFixtureFactory::createVariant($product, [], ['normailPrice' => 120, 'wholesalePrice' => 80, 'stock' => 3])['variant'];
        $second = OnlineStoreFixtureFactory::createVariant($product, [], ['normailPrice' => 130, 'wholesalePrice' => 90, 'stock' => 6])['variant'];
        $listing = OnlineStoreListing::query()->forceCreate(['product_id' => $product->id, 'status' => 'published', 'readiness_state' => 'complete']);
        $this->makeListingCustomerVisible($listing);
        $customer = OnlineStoreFixtureFactory::createCustomer();
        $order = SalesOrder::query()->forceCreate(['serial_number' => 'TEST-RESERVATION-1', 'customer_id' => $customer->id, 'status' => SalesOrderStatus::Unconfirmed->value, 'reserves_stock' => true, 'subtotal' => 0, 'discount' => 0, 'calculated_total' => 0, 'total' => 0]);
        SalesOrderItem::query()->forceCreate(['sales_order_id' => $order->id, 'product_id' => $product->id, 'size_color_id' => $first->id, 'product_name' => 'Fixture', 'quantity' => 2, 'reserved_qty' => 2, 'dispatched_qty' => 0, 'delivered_qty' => 0, 'returned_qty' => 0, 'unit_price' => 120, 'line_total' => 240, 'is_hidden' => false]);

        $prices = app(StorePriceResolver::class)->resolve($product->fresh());
        $availability = app(StoreAvailabilityService::class)->resolve($product->fresh(), $listing);
        $this->assertSame(100.0, $prices['retail']);
        $this->assertSame(70.0, $prices['wholesale']);
        $this->assertSame([120.0, 130.0], collect($prices['variants'])->pluck('retail')->all());
        $this->assertSame([80.0, 90.0], collect($prices['variants'])->pluck('wholesale')->all());
        $this->assertSame(7, $availability['available_qty']);
        $this->assertSame([1, 6], collect($availability['variants'])->pluck('available_qty')->all());
        $this->assertTrue($availability['purchasable']);
        $this->assertSame(100.0, (float) $product->fresh()->normailPrice);
        $this->assertSame(3, (int) $first->fresh()->stock);
        $this->assertSame(6, (int) $second->fresh()->stock);

        $product->forceFill(['normailPrice' => 105, 'wholesalePrice' => 75])->save();
        $second->forceFill(['stock' => 4])->save();
        $this->assertSame(105.0, app(StorePriceResolver::class)->resolve($product->fresh())['retail']);
        $this->assertSame(5, app(StoreAvailabilityService::class)->resolve($product->fresh(), $listing)['available_qty']);
    }

    public function test_falsified_values_are_rejected_and_zero_stock_remains_visible_not_purchasable(): void
    {
        $product = OnlineStoreFixtureFactory::createProduct(['descriptionEng' => 'Description', 'stock' => 0]);
        $admin = OnlineStoreFixtureFactory::createAuthenticatedAdminActor();
        $listing = OnlineStoreListing::query()->forceCreate(['product_id' => $product->id, 'status' => 'published', 'readiness_state' => 'complete', 'created_by' => $admin->id]);
        $this->makeListingCustomerVisible($listing);

        $this->putJson('/api/online-store/listings/'.$listing->id.'/media', ['items' => [], 'price' => 1, 'stock' => 99])->assertUnprocessable();
        $availability = app(StoreAvailabilityService::class)->resolve($product->fresh(), $listing);
        $this->assertTrue($availability['visible']);
        $this->assertFalse($availability['purchasable']);
        $this->assertSame(0, $availability['available_qty']);
        $this->assertSame(0, (int) $product->fresh()->stock);
    }

    public function test_online_stock_limit_caps_store_availability_without_changing_product_stock(): void
    {
        $product = OnlineStoreFixtureFactory::createProduct(['descriptionEng' => 'Description', 'stock' => 25]);
        $listing = OnlineStoreListing::query()->forceCreate([
            'product_id' => $product->id,
            'status' => 'published',
            'readiness_state' => 'complete',
            'online_stock_limit' => 10,
        ]);
        $this->makeListingCustomerVisible($listing);

        $availability = app(StoreAvailabilityService::class)->resolve($product->fresh(), $listing->fresh());

        $this->assertSame(10, $availability['available_qty']);
        $this->assertSame(25, $availability['inventory_available_qty']);
        $this->assertSame(10, $availability['online_stock_limit']);
        $this->assertFalse($availability['uses_full_inventory']);
        $this->assertSame(25, (int) $product->fresh()->stock);

        $listing->forceFill(['online_stock_limit' => null])->save();
        $availability = app(StoreAvailabilityService::class)->resolve($product->fresh(), $listing->fresh());
        $this->assertSame(25, $availability['available_qty']);
        $this->assertTrue($availability['uses_full_inventory']);
    }

    public function test_storefront_serialization_handles_an_archived_product_as_incomplete(): void
    {
        $product = OnlineStoreFixtureFactory::createProduct(['descriptionEng' => 'Description']);
        $listing = OnlineStoreListing::query()->forceCreate([
            'product_id' => $product->id, 'status' => 'published', 'readiness_state' => 'complete',
        ]);
        $this->makeListingCustomerVisible($listing);
        $product->delete();

        $payload = (new StorefrontListingResource($listing->fresh('product')))->toArray(request());

        $this->assertSame('incomplete', $payload['readiness_state']);
        $this->assertContains('missing_product', $payload['readiness_issues']);
        $this->assertSame([], $payload['media']);
        $this->assertNull($payload['base_prices']['retail']);
        $this->assertFalse($payload['availability']['visible']);
        $this->assertFalse($payload['availability']['purchasable']);
    }

    private function makeListingCustomerVisible(OnlineStoreListing $listing): void
    {
        $imageId = 2000000000 + (int) $listing->id;
        $categoryId = 1900000000 + (int) $listing->id;
        NormalImageProduct::query()->forceCreate(['id' => $imageId, 'itemId' => $listing->product_id, 'imageUrl' => 'fixture.jpg']);
        \Illuminate\Support\Facades\DB::table('online_store_categories')->insert(['id' => $categoryId, 'name_translations' => json_encode(['en' => 'Category']), 'is_active' => true, 'show_on_home' => false, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
        \Illuminate\Support\Facades\DB::table('online_store_category_listing')->insert(['online_store_category_id' => $categoryId, 'online_store_listing_id' => $listing->id, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
        \Illuminate\Support\Facades\DB::table('online_store_media_presentations')->insert(['online_store_listing_id' => $listing->id, 'source_type' => 'normal_image', 'source_id' => $imageId, 'is_main' => true, 'is_visible' => true, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
    }
}
