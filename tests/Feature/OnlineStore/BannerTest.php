<?php

namespace Tests\Feature\OnlineStore;

use App\Models\OnlineStore\OnlineStoreBanner;
use App\Models\OnlineStore\OnlineStoreListing;
use App\Services\OnlineStore\BannerService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\OnlineStoreFixtureFactory;
use Tests\TestCase;

class BannerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (filter_var(env('ONLINE_STORE_DUMP_DERIVED_SCHEMA_READY', false), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Fresh-schema tests cannot alter restored dump-derived state.');
        }
        Artisan::call('migrate:fresh', ['--force' => true]);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-02 12:00:00', config('app.timezone')));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_banner_action_schedule_url_and_deterministic_active_window(): void
    {
        OnlineStoreFixtureFactory::createAuthenticatedAdminActor();
        $this->postJson('/api/online-store/home-sections', ['key' => 'hero', 'section_type' => 'hero', 'selection_mode' => 'dedicated_banners'])->assertCreated();
        $this->postJson('/api/online-store/banners', ['image_path' => 'fixture.jpg', 'action_type' => 'url', 'action_url' => 'javascript:alert(1)'])->assertUnprocessable();
        $this->postJson('/api/online-store/banners', ['image_path' => 'fixture.jpg', 'action_type' => 'listing', 'action_target_id' => 999999])->assertUnprocessable();
        $this->postJson('/api/online-store/banners', ['image_path' => 'fixture.jpg', 'action_type' => 'none', 'action_url' => 'https://example.invalid'])->assertUnprocessable();
        $this->postJson('/api/online-store/banners', ['image_path' => 'fixture.jpg', 'action_type' => 'none', 'starts_at' => '2026-10-03 12:00:00', 'ends_at' => '2026-10-02 12:00:00'])->assertUnprocessable();

        $second = $this->postJson('/api/online-store/banners', ['image_path' => 'public/OnlineStore/Content/second.jpg', 'action_type' => 'url', 'action_url' => 'https://example.invalid/two', 'is_active' => true, 'sort_order' => 1, 'starts_at' => '2026-10-02 10:00:00', 'ends_at' => '2026-10-02 14:00:00'])->assertCreated()->json('data');
        $first = $this->postJson('/api/online-store/banners', ['image_path' => 'first.jpg', 'action_type' => 'none', 'is_active' => true, 'sort_order' => 1, 'starts_at' => '2026-10-02 10:00:00', 'ends_at' => '2026-10-02 14:00:00'])->assertCreated()->json('data');
        $this->postJson('/api/online-store/banners', ['image_path' => 'future.jpg', 'action_type' => 'none', 'is_active' => true, 'sort_order' => 0, 'starts_at' => '2026-10-03 10:00:00'])->assertCreated();

        $this->assertSame('2026-10-02T07:00:00.000000Z', $second['starts_at']);

        $items = collect($this->getJson('/OnlineStore/Home')->assertOk()->json('data.sections.0.items'));
        $this->assertSame(collect([$second['id'], $first['id']])->sort()->values()->all(), $items->pluck('id')->all());
        $this->assertSame(['OnlineStore/Content/second.jpg', 'first.jpg'], $items->pluck('image_path')->all());
        $this->assertNotContains('future.jpg', $items->pluck('image_path')->all());

        $ads = collect($this->postJson('/OnlineAds/GetAllAds')->assertOk()->json('rows'));
        $this->assertSame(['OnlineStore/Content/second.jpg', 'first.jpg'], $ads->pluck('imageUrl')->all());

        $this->postJson('/OnlineStore/Banners/'.$first['id'].'/Click')->assertNoContent();
        $this->postJson('/OnlineStore/Banners/'.$first['id'].'/Click')->assertNoContent();
        $this->assertSame(2, OnlineStoreBanner::query()->findOrFail($first['id'])->click_count);
    }

    public function test_internal_targets_must_be_customer_visible_and_use_the_supplied_reference_time(): void
    {
        $admin = OnlineStoreFixtureFactory::createAuthenticatedAdminActor();
        $product = OnlineStoreFixtureFactory::createProduct();
        $activeCategory = $this->postJson('/api/online-store/categories', ['name_translations' => ['en' => 'Active']])->assertCreated()->json('data');
        $inactiveCategory = $this->postJson('/api/online-store/categories', ['name_translations' => ['en' => 'Inactive'], 'is_active' => false])->assertCreated()->json('data');

        $eligible = OnlineStoreListing::query()->forceCreate(['product_id' => $product->id, 'status' => 'published', 'readiness_state' => 'complete']);
        DB::table('online_store_category_listing')->insert(['online_store_category_id' => $activeCategory['id'], 'online_store_listing_id' => $eligible->id, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
        foreach ([['draft', 'complete'], ['published', 'incomplete'], ['hidden', 'complete']] as [$status, $readiness]) {
            $otherProduct = OnlineStoreFixtureFactory::createProduct();
            $listing = OnlineStoreListing::query()->forceCreate(['product_id' => $otherProduct->id, 'status' => $status, 'readiness_state' => $readiness]);
            DB::table('online_store_category_listing')->insert(['online_store_category_id' => $activeCategory['id'], 'online_store_listing_id' => $listing->id, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
            $this->postJson('/api/online-store/banners', ['image_path' => $status.'.jpg', 'action_type' => 'listing', 'action_target_id' => $listing->id])->assertUnprocessable();
        }
        $this->postJson('/api/online-store/banners', ['image_path' => 'eligible.jpg', 'action_type' => 'listing', 'action_target_id' => $eligible->id])->assertCreated();
        $this->postJson('/api/online-store/banners', ['image_path' => 'inactive-category.jpg', 'action_type' => 'category', 'action_target_id' => $inactiveCategory['id']])->assertUnprocessable();
        $this->postJson('/api/online-store/banners', ['image_path' => 'active-category.jpg', 'action_type' => 'category', 'action_target_id' => $activeCategory['id']])->assertCreated();

        DB::table('online_store_promotions')->insert([
            ['id' => 1900000001, 'name' => 'Inactive', 'discount_type' => 'fixed', 'discount_value' => 1, 'applies_to' => 'both', 'scope' => 'global', 'starts_at' => null, 'ends_at' => null, 'is_active' => false, 'priority' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 1900000002, 'name' => 'Future', 'discount_type' => 'fixed', 'discount_value' => 1, 'applies_to' => 'both', 'scope' => 'global', 'starts_at' => '2026-10-05 07:00:00', 'ends_at' => '2026-10-05 09:00:00', 'is_active' => true, 'priority' => 0, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->postJson('/api/online-store/banners', ['image_path' => 'inactive-promotion.jpg', 'action_type' => 'promotion', 'action_target_id' => 1900000001])->assertUnprocessable();
        $this->postJson('/api/online-store/banners', ['image_path' => 'future-promotion.jpg', 'action_type' => 'promotion', 'action_target_id' => 1900000002])->assertUnprocessable();

        $reference = CarbonImmutable::parse('2026-10-05 10:00:00', config('app.timezone'));
        $banner = app(BannerService::class)->save(null, ['image_path' => 'promotion.jpg', 'action_type' => 'promotion', 'action_target_id' => 1900000002, 'is_active' => true], $admin->id, $reference);
        $this->assertTrue(app(BannerService::class)->active($reference)->contains(fn (OnlineStoreBanner $item) => $item->is($banner)));
        $this->assertFalse(app(BannerService::class)->active(CarbonImmutable::parse('2026-10-05 13:00:00', config('app.timezone')))->contains(fn (OnlineStoreBanner $item) => $item->is($banner)));
    }
}
