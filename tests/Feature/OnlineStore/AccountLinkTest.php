<?php

namespace Tests\Feature\OnlineStore;

use App\Services\OnlineStore\StoreIdentityService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\Support\OnlineStoreFixtureFactory;
use Tests\TestCase;

class AccountLinkTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (filter_var(env('ONLINE_STORE_DUMP_DERIVED_SCHEMA_READY', false), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Fresh-schema tests cannot alter restored dump-derived state.');
        } Artisan::call('migrate:fresh', ['--force' => true]);
    }

    public function test_role_matching_party_and_verification_are_explicit(): void
    {
        $admin = OnlineStoreFixtureFactory::createAdminActor();
        $user = OnlineStoreFixtureFactory::createStoreActor();
        $customer = OnlineStoreFixtureFactory::createCustomer();
        $link = app(StoreIdentityService::class)->save($admin, $user, ['role' => 'customer', 'customer_id' => $customer->id, 'account_source' => 'admin_app', 'status' => 'active']);
        $this->assertSame('customer', $link->role);
        $this->assertSame($customer->id, $link->customer_id);
        $this->assertNull($link->seller_id);
        $this->assertNotNull($link->verified_at);
        $this->assertSame($admin->id, $link->verified_by);
    }

    public function test_duplicate_party_or_user_role_conflicts_and_blocked_users_are_rejected(): void
    {
        $admin = OnlineStoreFixtureFactory::createAdminActor();
        $first = OnlineStoreFixtureFactory::createStoreActor();
        $second = OnlineStoreFixtureFactory::createStoreActor();
        $customer = OnlineStoreFixtureFactory::createCustomer();
        $service = app(StoreIdentityService::class);
        $service->save($admin, $first, ['role' => 'customer', 'customer_id' => $customer->id, 'account_source' => 'import', 'status' => 'pending']);
        $this->expectException(ValidationException::class);
        $service->save($admin, $second, ['role' => 'customer', 'customer_id' => $customer->id, 'account_source' => 'admin_app', 'status' => 'active']);
    }

    public function test_blocked_user_cannot_be_linked(): void
    {
        $this->expectException(ValidationException::class);
        app(StoreIdentityService::class)->save(OnlineStoreFixtureFactory::createAdminActor(), OnlineStoreFixtureFactory::createStoreActor(['is_blocked' => true]), ['role' => 'seller', 'seller_id' => OnlineStoreFixtureFactory::createSeller()->id, 'account_source' => 'store_app', 'status' => 'pending']);
    }

    public function test_only_active_verified_link_resolves(): void
    {
        $this->expectException(ValidationException::class);
        app(StoreIdentityService::class)->activeLink(OnlineStoreFixtureFactory::createStoreActor());
    }

    public function test_inaccessible_account_link_is_not_disclosed(): void
    {
        $admin = OnlineStoreFixtureFactory::createAdminActor();
        $link = app(StoreIdentityService::class)->save($admin, OnlineStoreFixtureFactory::createStoreActor(), [
            'role' => 'customer', 'customer_id' => OnlineStoreFixtureFactory::createCustomer()->id,
            'account_source' => 'admin_app', 'status' => 'pending',
        ]);
        Sanctum::actingAs(OnlineStoreFixtureFactory::createStoreActor(['type' => 'employee']));
        $this->patchJson('/api/online-store/account-links/'.$link->id, ['status' => 'suspended'])->assertNotFound();
    }
}
