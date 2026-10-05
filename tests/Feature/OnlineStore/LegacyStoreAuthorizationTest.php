<?php

namespace Tests\Feature\OnlineStore;

use App\Models\Store\StoreUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\OnlineStoreFixtureFactory;
use Tests\TestCase;

class LegacyStoreAuthorizationTest extends TestCase
{
    use RefreshDatabase {
        refreshTestDatabase as baseRefreshTestDatabase;
    }

    protected function refreshTestDatabase(): void
    {
        $this->requireDisposableDatabase();
        if (filter_var(env('ONLINE_STORE_PREMIGRATED_SCHEMA_READY', false), FILTER_VALIDATE_BOOL)) {
            $this->beginDatabaseTransaction();

            return;
        }
        $this->baseRefreshTestDatabase();
    }

    public function test_protected_user_and_order_writes_require_bearer_identity(): void
    {
        $this->postJson('/Users/Edit', ['id' => 1, 'fullName' => 'Attacker'])->assertUnauthorized();
        $this->postJson('/Users/BlockUserAndNotActive', ['userId' => 1])->assertUnauthorized();
        $this->postJson('/Orders/ManageOrder', ['details' => [['itemId' => 1, 'quantity' => 1]]])->assertUnauthorized();
        $this->postJson('/Orders/CancelOrder', ['id' => 1])->assertUnauthorized();
        $this->postJson('/Orders/GetAllOrdersByUserId', ['userId' => 1])->assertUnauthorized();
    }

    public function test_authenticated_user_profile_reads_and_writes_are_derived_from_bearer_identity(): void
    {
        $first = OnlineStoreFixtureFactory::createStoreActor(['name' => 'First']);
        $second = OnlineStoreFixtureFactory::createStoreActor(['name' => 'Second']);
        $headers = $this->storeHeaders($first->id);

        $this->postJson('/Users/GetById', ['id' => $second->id], $headers)->assertNotFound();
        $this->postJson('/Users/Edit', ['id' => $second->id, 'fullName' => 'Stolen'], $headers)->assertNotFound();
        $this->postJson('/Users/BlockUserAndNotActive', ['userId' => $second->id], $headers)->assertNotFound();

        $this->postJson('/Users/Edit', ['id' => $first->id, 'fullName' => 'Owned', 'typeUser' => 'admin'], $headers)
            ->assertOk()->assertJsonPath('fullName', 'Owned');
        $this->assertSame('User', $first->fresh()->type);
        $this->assertSame('Second', $second->fresh()->name);
    }

    public function test_foreign_order_history_and_cancel_ids_are_not_accepted(): void
    {
        $first = OnlineStoreFixtureFactory::createStoreActor();
        $second = OnlineStoreFixtureFactory::createStoreActor();
        $headers = $this->storeHeaders($first->id);

        $this->postJson('/Orders/GetAllOrdersByUserId', ['userId' => $second->id], $headers)->assertNotFound();
        $this->postJson('/Orders/CancelOrder', ['id' => 999999, 'userId' => $second->id], $headers)->assertNotFound();
    }

    public function test_public_catalog_routes_remain_accessible_without_bearer_identity(): void
    {
        $this->postJson('/OnlineAds/GetAllAds')->assertOk()->assertExactJson(['rows' => [], 'total' => 0, 'totalNotFiltered' => 0]);
        $this->postJson('/Comments/GetAllCommentsToItem')->assertOk()->assertExactJson(['rows' => [], 'total' => 0, 'totalNotFiltered' => 0]);
        $this->postJson('/Items/GetAllItemByName', ['Name' => '__missing__'])->assertOk()->assertJsonStructure(['rows', 'paginationInfo']);
        $this->postJson('/MainCategorys/GetAllShowMainCategories')->assertOk()->assertJsonStructure(['rows', 'paginationInfo']);
        $this->postJson('/SupCategorys/GetAllShowSupCategories')->assertOk()->assertJsonStructure(['rows', 'paginationInfo']);
        $this->postJson('/Cities/GetAllCities')->assertOk()->assertJsonStructure(['rows', 'paginationInfo']);
        $this->postJson('/Settings/CheckSetting')->assertOk()->assertJsonStructure(['data', 'isSuccess', 'error', 'isFailure']);
    }

    private function storeHeaders(int $userId): array
    {
        $token = StoreUser::query()->findOrFail($userId)->createToken('store-test')->plainTextToken;

        return ['Authorization' => 'Bearer '.$token];
    }
}
