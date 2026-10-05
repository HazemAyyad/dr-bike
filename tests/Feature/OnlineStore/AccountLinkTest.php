<?php

namespace Tests\Feature\OnlineStore;

use App\Services\OnlineStore\StoreIdentityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\Support\OnlineStoreFixtureFactory;
use Tests\TestCase;

class AccountLinkTest extends TestCase
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

    public function test_admin_can_create_and_update_a_link_with_complete_attribution_and_relations(): void
    {
        $admin = OnlineStoreFixtureFactory::createAuthenticatedAdminActor();
        $firstUser = OnlineStoreFixtureFactory::createStoreActor(['name' => 'First Store User']);
        $secondUser = OnlineStoreFixtureFactory::createStoreActor(['name' => 'Second Store User']);
        $customer = OnlineStoreFixtureFactory::createCustomer(['name' => 'Linked Customer']);
        $seller = OnlineStoreFixtureFactory::createSeller(['name' => 'Linked Supplier']);

        $create = $this->postJson('/api/online-store/account-links', [
            'user_id' => $firstUser->id,
            'role' => 'customer',
            'customer_id' => $customer->id,
            'account_source' => 'admin_app',
            'status' => 'pending',
        ])->assertCreated();

        $linkId = $create->json('data.id');
        $this->assertDatabaseHas('online_store_account_links', [
            'id' => $linkId,
            'user_id' => $firstUser->id,
            'customer_id' => $customer->id,
            'seller_id' => null,
            'role' => 'customer',
            'account_source' => 'admin_app',
            'status' => 'pending',
            'linked_by' => $admin->id,
            'verified_by' => null,
            'verified_at' => null,
        ]);

        $this->patchJson('/api/online-store/account-links/'.$linkId, [
            'user_id' => $secondUser->id,
            'role' => 'seller',
            'seller_id' => $seller->id,
            'account_source' => 'import',
            'status' => 'active',
        ])->assertOk()
            ->assertJsonPath('data.user.id', $secondUser->id)
            ->assertJsonPath('data.seller.id', $seller->id)
            ->assertJsonPath('data.customer', null)
            ->assertJsonPath('data.linked_by.id', $admin->id)
            ->assertJsonPath('data.verified_by.id', $admin->id);

        $this->assertDatabaseHas('online_store_account_links', [
            'id' => $linkId,
            'user_id' => $secondUser->id,
            'customer_id' => null,
            'seller_id' => $seller->id,
            'role' => 'seller',
            'account_source' => 'import',
            'status' => 'active',
            'linked_by' => $admin->id,
            'verified_by' => $admin->id,
        ]);
        $this->assertNotNull($secondUser->fresh()->onlineStoreAccountLinks()->find($linkId)?->verified_at);
    }

    public function test_account_link_index_filters_by_identity_role_source_status_and_party_name(): void
    {
        $admin = OnlineStoreFixtureFactory::createAuthenticatedAdminActor();
        $targetUser = OnlineStoreFixtureFactory::createStoreActor(['name' => 'Target Store User']);
        $otherUser = OnlineStoreFixtureFactory::createStoreActor(['name' => 'Other Store User']);
        $targetCustomer = OnlineStoreFixtureFactory::createCustomer(['name' => 'Needle Trading']);
        $otherSeller = OnlineStoreFixtureFactory::createSeller(['name' => 'Unrelated Supplier']);
        $service = app(StoreIdentityService::class);
        $target = $service->save($admin, $targetUser, [
            'role' => 'customer',
            'customer_id' => $targetCustomer->id,
            'account_source' => 'admin_app',
            'status' => 'active',
        ]);
        $service->save($admin, $otherUser, [
            'role' => 'seller',
            'seller_id' => $otherSeller->id,
            'account_source' => 'import',
            'status' => 'pending',
        ]);

        $filters = [
            'user_id' => $targetUser->id,
            'customer_id' => $targetCustomer->id,
            'role' => 'customer',
            'account_source' => 'admin_app',
            'status' => 'active',
            'search' => 'Needle',
        ];

        $this->getJson('/api/online-store/account-links?'.http_build_query($filters))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $target->id)
            ->assertJsonPath('data.0.user.id', $targetUser->id)
            ->assertJsonPath('data.0.customer.id', $targetCustomer->id)
            ->assertJsonPath('data.0.seller', null)
            ->assertJsonPath('data.0.linked_by.id', $admin->id)
            ->assertJsonPath('data.0.verified_by.id', $admin->id);

        $this->getJson('/api/online-store/account-links?seller_id='.$otherSeller->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.seller.id', $otherSeller->id);
    }

    public function test_user_role_and_party_conflicts_are_rejected_without_replacing_the_existing_link(): void
    {
        $admin = OnlineStoreFixtureFactory::createAdminActor();
        $user = OnlineStoreFixtureFactory::createStoreActor();
        $firstCustomer = OnlineStoreFixtureFactory::createCustomer();
        $secondCustomer = OnlineStoreFixtureFactory::createCustomer();
        $service = app(StoreIdentityService::class);
        $existing = $service->save($admin, $user, [
            'role' => 'customer',
            'customer_id' => $firstCustomer->id,
            'account_source' => 'admin_app',
            'status' => 'active',
        ]);

        try {
            $service->save($admin, $user, [
                'role' => 'customer',
                'customer_id' => $secondCustomer->id,
                'account_source' => 'import',
                'status' => 'pending',
            ]);
            $this->fail('The duplicate user/role link was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('account_link', $exception->errors());
        }

        $this->assertDatabaseCount('online_store_account_links', 1);
        $this->assertDatabaseHas('online_store_account_links', [
            'id' => $existing->id,
            'customer_id' => $firstCustomer->id,
            'status' => 'active',
        ]);
    }

    public function test_store_account_discovery_includes_unlinked_and_explicitly_linked_users_only(): void
    {
        $token = 'discovery-'.Str::lower(Str::random(12));
        $partyOnlyTerm = 'party-only-'.Str::lower(Str::random(12));
        $admin = OnlineStoreFixtureFactory::createAuthenticatedAdminActor(['name' => $token.' admin']);
        $unlinked = OnlineStoreFixtureFactory::createStoreActor(['name' => $token.' unlinked']);
        $customerUser = OnlineStoreFixtureFactory::createStoreActor(['name' => $token.' customer']);
        $sellerUser = OnlineStoreFixtureFactory::createStoreActor(['name' => $token.' seller']);
        $dualRoleUser = OnlineStoreFixtureFactory::createStoreActor(['name' => $token.' dual']);
        $blocked = OnlineStoreFixtureFactory::createStoreActor([
            'name' => $token.' blocked',
            'is_blocked' => true,
        ]);
        $employee = OnlineStoreFixtureFactory::createStoreActor([
            'name' => $token.' employee',
            'type' => 'employee',
        ]);
        $otherType = OnlineStoreFixtureFactory::createStoreActor([
            'name' => $token.' other',
            'type' => 'service',
        ]);
        $archived = OnlineStoreFixtureFactory::createStoreActor(['name' => $token.' archived']);
        $archived->delete();

        $service = app(StoreIdentityService::class);
        $customerLink = $service->save($admin, $customerUser, [
            'role' => 'customer',
            'customer_id' => OnlineStoreFixtureFactory::createCustomer(['name' => $partyOnlyTerm])->id,
            'account_source' => 'admin_app',
            'status' => 'active',
        ]);
        $sellerLink = $service->save($admin, $sellerUser, [
            'role' => 'seller',
            'seller_id' => OnlineStoreFixtureFactory::createSeller(['name' => $partyOnlyTerm])->id,
            'account_source' => 'import',
            'status' => 'pending',
        ]);
        $dualCustomerLink = $service->save($admin, $dualRoleUser, [
            'role' => 'customer',
            'customer_id' => OnlineStoreFixtureFactory::createCustomer()->id,
            'account_source' => 'admin_app',
            'status' => 'active',
        ]);
        $dualSellerLink = $service->save($admin, $dualRoleUser, [
            'role' => 'seller',
            'seller_id' => OnlineStoreFixtureFactory::createSeller()->id,
            'account_source' => 'store_app',
            'status' => 'active',
        ]);

        $response = $this->getJson('/api/online-store/accounts?'.http_build_query([
            'search' => $token,
            'per_page' => 100,
        ]))->assertOk();
        $accounts = collect($response->json('data'));

        $this->assertEqualsCanonicalizing(
            [$unlinked->id, $customerUser->id, $sellerUser->id, $dualRoleUser->id, $blocked->id],
            $accounts->pluck('id')->all(),
        );
        $this->assertNotContains($admin->id, $accounts->pluck('id')->all());
        $this->assertNotContains($employee->id, $accounts->pluck('id')->all());
        $this->assertNotContains($otherType->id, $accounts->pluck('id')->all());
        $this->assertNotContains($archived->id, $accounts->pluck('id')->all());

        $this->assertSame([], $accounts->firstWhere('id', $unlinked->id)['links']);
        $this->assertSame([$customerLink->id], collect($accounts->firstWhere('id', $customerUser->id)['links'])->pluck('id')->all());
        $this->assertSame([$sellerLink->id], collect($accounts->firstWhere('id', $sellerUser->id)['links'])->pluck('id')->all());
        $this->assertEqualsCanonicalizing(
            [$dualCustomerLink->id, $dualSellerLink->id],
            collect($accounts->firstWhere('id', $dualRoleUser->id)['links'])->pluck('id')->all(),
        );
        $this->assertSame(1, $accounts->where('id', $dualRoleUser->id)->count());
        $this->assertTrue($accounts->firstWhere('id', $blocked->id)['is_blocked']);
        $this->assertFalse($accounts->firstWhere('id', $blocked->id)['is_linkable']);

        $this->assertSame(
            ['id', 'name', 'email', 'phone', 'is_blocked', 'is_linkable', 'links'],
            array_keys($accounts->firstWhere('id', $customerUser->id)),
        );
        $this->assertSame(
            ['id', 'role', 'customer_id', 'seller_id', 'status', 'verified_at', 'account_source'],
            array_keys($accounts->firstWhere('id', $customerUser->id)['links'][0]),
        );
        $this->getJson('/api/online-store/accounts?search='.$partyOnlyTerm)
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_store_account_discovery_searches_user_name_email_and_phone(): void
    {
        OnlineStoreFixtureFactory::createAuthenticatedAdminActor();
        $token = Str::lower(Str::random(14));
        $phone = '059'.random_int(1_000_000, 9_999_999);
        $user = OnlineStoreFixtureFactory::createStoreActor([
            'name' => 'Name '.$token,
            'email' => 'email-'.$token.'@example.invalid',
            'phone' => $phone,
        ]);

        foreach ([$token, 'email-'.$token, $phone] as $search) {
            $this->getJson('/api/online-store/accounts?search='.urlencode($search))
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', $user->id);
        }
    }

    public function test_store_account_discovery_is_deterministically_paginated(): void
    {
        OnlineStoreFixtureFactory::createAuthenticatedAdminActor();
        $token = 'pagination-'.Str::lower(Str::random(12));
        $first = OnlineStoreFixtureFactory::createStoreActor(['name' => $token.' A']);
        $second = OnlineStoreFixtureFactory::createStoreActor(['name' => $token.' B']);
        $third = OnlineStoreFixtureFactory::createStoreActor(['name' => $token.' C']);

        $pageOne = $this->getJson('/api/online-store/accounts?'.http_build_query([
            'search' => $token,
            'per_page' => 2,
        ]))->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('per_page', 2)
            ->assertJsonPath('total', 3);
        $this->assertSame([$first->id, $second->id], collect($pageOne->json('data'))->pluck('id')->all());

        $pageTwo = $this->getJson('/api/online-store/accounts?'.http_build_query([
            'search' => $token,
            'per_page' => 2,
            'page' => 2,
        ]))->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame([$third->id], collect($pageTwo->json('data'))->pluck('id')->all());

        $this->getJson('/api/online-store/accounts?'.http_build_query(['search' => str_repeat('x', 256)]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('search');
        $this->getJson('/api/online-store/accounts?per_page=101')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');
    }
}
