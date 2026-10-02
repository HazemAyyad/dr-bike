<?php

namespace Tests\Feature\OnlineStore;

use App\Models\OnlineStore\OnlineStoreAccountLink;
use App\Models\OnlineStore\OnlineStoreListing;
use App\Services\OnlineStore\CouponService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\ValidationException;
use Tests\Support\OnlineStoreFixtureFactory;
use Tests\TestCase;

class CouponTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (filter_var(env('ONLINE_STORE_DUMP_DERIVED_SCHEMA_READY', false), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Fresh-schema tests cannot alter restored dump-derived state.');
        } Artisan::call('migrate:fresh', ['--force' => true]);
    }

    public function test_code_normalization_minimum_context_account_and_non_negative_amount(): void
    {
        $admin = OnlineStoreFixtureFactory::createAdminActor();
        $user = OnlineStoreFixtureFactory::createStoreActor();
        $customer = OnlineStoreFixtureFactory::createCustomer();
        $link = OnlineStoreAccountLink::query()->forceCreate(['user_id' => $user->id, 'customer_id' => $customer->id, 'role' => 'customer', 'account_source' => 'admin_app', 'status' => 'active', 'verified_at' => now()]);
        $service = app(CouponService::class);
        $coupon = $service->save($admin, ['code' => ' save10 ', 'discount_type' => 'fixed', 'discount_value' => 1000, 'minimum_order' => 50, 'eligible_account_type' => 'customer', 'applies_to' => 'retail', 'scope' => 'global', 'is_active' => true, 'targets' => []]);
        $this->assertSame('SAVE10', $coupon->code);
        $eligible = $service->findEligible('save10', $user, $link, [], 60, 'retail');
        $this->assertSame(60.0, $service->amount($eligible, 60));
    }

    public function test_ineligible_minimum_is_rejected(): void
    {
        $admin = OnlineStoreFixtureFactory::createAdminActor();
        $user = OnlineStoreFixtureFactory::createStoreActor();
        $customer = OnlineStoreFixtureFactory::createCustomer();
        $link = OnlineStoreAccountLink::query()->forceCreate(['user_id' => $user->id, 'customer_id' => $customer->id, 'role' => 'customer', 'account_source' => 'admin_app', 'status' => 'active', 'verified_at' => now()]);
        $service = app(CouponService::class);
        $service->save($admin, ['code' => 'MIN', 'discount_type' => 'percentage', 'discount_value' => 10, 'minimum_order' => 100, 'eligible_account_type' => 'both', 'applies_to' => 'both', 'scope' => 'global', 'is_active' => true, 'targets' => []]);
        $this->expectException(ValidationException::class);
        $service->findEligible('min', $user, $link, [], 99, 'retail');
    }

    public function test_code_is_case_insensitively_unique(): void
    {
        $admin = OnlineStoreFixtureFactory::createAdminActor();
        $service = app(CouponService::class);
        $definition = ['code' => 'Unique', 'discount_type' => 'fixed', 'discount_value' => 1, 'minimum_order' => 0,
            'eligible_account_type' => 'both', 'applies_to' => 'both', 'scope' => 'global', 'is_active' => false, 'targets' => []];
        $service->save($admin, $definition);
        $this->expectException(ValidationException::class);
        $service->save($admin, [...$definition, 'code' => 'unique']);
    }

    public function test_targeted_coupon_discounts_only_the_eligible_amount(): void
    {
        $listing = OnlineStoreListing::query()->forceCreate(['product_id' => OnlineStoreFixtureFactory::createProduct()->id, 'status' => 'published', 'readiness_state' => 'complete']);
        $service = app(CouponService::class);
        $coupon = $service->save(OnlineStoreFixtureFactory::createAdminActor(), ['code' => 'TARGET', 'discount_type' => 'percentage', 'discount_value' => 50, 'minimum_order' => 0, 'eligible_account_type' => 'both', 'applies_to' => 'both', 'scope' => 'targeted', 'is_active' => true, 'targets' => [['target_type' => 'listing', 'target_id' => $listing->id]]]);
        $items = [['listing_id' => $listing->id, 'category_ids' => [], 'line_total' => 40], ['listing_id' => $listing->id + 1, 'category_ids' => [], 'line_total' => 60]];
        $this->assertSame(40.0, $service->eligibleSubtotal($coupon, $items));
        $this->assertSame(20.0, $service->amount($coupon, $service->eligibleSubtotal($coupon, $items)));
    }
}
