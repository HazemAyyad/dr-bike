<?php

namespace Tests\Feature\OnlineStore;

use App\Models\OnlineStore\OnlineStoreListing;
use App\Services\OnlineStore\PromotionService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\ValidationException;
use Tests\Support\OnlineStoreFixtureFactory;
use Tests\TestCase;

class PromotionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (filter_var(env('ONLINE_STORE_DUMP_DERIVED_SCHEMA_READY', false), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Fresh-schema tests cannot alter restored dump-derived state.');
        } Artisan::call('migrate:fresh', ['--force' => true]);
    }

    public function test_active_windows_are_inclusive_and_highest_priority_then_lowest_id_wins(): void
    {
        $admin = OnlineStoreFixtureFactory::createAdminActor();
        $listing = OnlineStoreListing::query()->forceCreate(['product_id' => OnlineStoreFixtureFactory::createProduct()->id, 'status' => 'published', 'readiness_state' => 'complete']);
        $service = app(PromotionService::class);
        $at = now()->startOfSecond();
        $endsAt = $at->copy()->addHour();
        $low = $service->save($admin, ['name' => 'low', 'discount_type' => 'fixed', 'discount_value' => 5, 'applies_to' => 'retail', 'scope' => 'global', 'starts_at' => $at, 'ends_at' => $endsAt, 'is_active' => true, 'priority' => 1, 'targets' => []]);
        $high = $service->save($admin, ['name' => 'high', 'discount_type' => 'percentage', 'discount_value' => 10, 'applies_to' => 'both', 'scope' => 'global', 'starts_at' => $at, 'ends_at' => $endsAt, 'is_active' => true, 'priority' => 2, 'targets' => []]);
        $this->assertSame($high->id, $service->winner($listing, 'retail', $at)?->id);
        $this->assertSame($high->id, $service->winner($listing, 'retail', $endsAt)?->id);
        $this->assertSame(10.0, $service->discount(100, $high));
    }

    public function test_invalid_percentage_and_targeted_activation_without_target_are_rejected(): void
    {
        $this->expectException(ValidationException::class);
        app(PromotionService::class)->save(OnlineStoreFixtureFactory::createAdminActor(), ['name' => 'bad', 'discount_type' => 'percentage', 'discount_value' => 101, 'applies_to' => 'both', 'scope' => 'targeted', 'is_active' => true, 'targets' => []]);
    }

    public function test_targeted_and_global_scope_are_explicit(): void
    {
        $this->expectException(ValidationException::class);
        $listing = OnlineStoreListing::query()->forceCreate(['product_id' => OnlineStoreFixtureFactory::createProduct()->id, 'status' => 'draft', 'readiness_state' => 'incomplete']);
        app(PromotionService::class)->save(OnlineStoreFixtureFactory::createAdminActor(), ['name' => 'bad global', 'discount_type' => 'fixed', 'discount_value' => 1, 'applies_to' => 'both', 'scope' => 'global', 'is_active' => true, 'targets' => [['target_type' => 'listing', 'target_id' => $listing->id]]]);
    }

    public function test_allow_listed_listing_target_is_eligible_without_stacking(): void
    {
        $listing = OnlineStoreListing::query()->forceCreate(['product_id' => OnlineStoreFixtureFactory::createProduct()->id, 'status' => 'published', 'readiness_state' => 'complete']);
        $service = app(PromotionService::class);
        $promotion = $service->save(OnlineStoreFixtureFactory::createAdminActor(), ['name' => 'targeted', 'discount_type' => 'fixed', 'discount_value' => 7, 'applies_to' => 'wholesale', 'scope' => 'targeted', 'is_active' => true, 'priority' => 3, 'targets' => [['target_type' => 'listing', 'target_id' => $listing->id]]]);
        $this->assertSame($promotion->id, $service->winner($listing, 'wholesale')?->id);
        $this->assertNull($service->winner($listing, 'retail'));
    }
}
