<?php

namespace Tests\Feature\OnlineStore;

use App\Models\OnlineStore\OnlineStoreReview;
use App\Models\Store\StoreUser;
use App\Services\OnlineStore\OnlineStoreReviewService;
use App\Services\OnlineStore\StoreIdentityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Tests\Support\OnlineStoreFixtureFactory;
use Tests\TestCase;

class LegacyCommentCompatibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (filter_var(env('ONLINE_STORE_DUMP_DERIVED_SCHEMA_READY', false), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Fresh-schema tests cannot alter restored dump-derived state.');
        }
        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    public function test_frozen_empty_result_and_route_contract_are_preserved(): void
    {
        $listRoute = Route::getRoutes()->match(Request::create('/Comments/GetAllCommentsToItem', 'POST'));
        $manageRoute = Route::getRoutes()->match(Request::create('/Comments/ManageComment', 'POST'));
        $this->assertSame('App\\Http\\Controllers\\API\\Store\\StoreCommentsController@getAllCommentsToItem', $listRoute->getActionName());
        $this->assertSame('App\\Http\\Controllers\\API\\Store\\StoreCommentsController@manageComment', $manageRoute->getActionName());

        $response = $this->postJson('/Comments/GetAllCommentsToItem?ItemId=999999', [
            'listRelatedObjects' => [],
            'entity' => ['nullable' => true],
            'listOrderOptions' => [],
            'paginationInfo' => ['pageIndex' => 0, 'pageSize' => 0],
        ])->assertOk();

        $this->assertSame([
            'rows' => [],
            'total' => 0,
            'totalNotFiltered' => 0,
        ], $response->json());
    }

    public function test_legacy_manage_shape_delegates_without_trusting_submitted_identity_or_state(): void
    {
        [$user, $customer, $token] = $this->linkedCustomerToken();
        $product = OnlineStoreFixtureFactory::createProduct();

        $response = $this->withToken($token)->postJson('/Comments/ManageComment', [
            'id' => 0,
            'comment' => 'legacy fixture',
            'productId' => $product->id,
            'productName' => 'untrusted product name',
            'rate' => 5,
            'userName' => 'untrusted name',
            'userAddId' => $user->id + 100,
            'isShow' => true,
            'dateAdd' => '2000-01-01T00:00:00.000Z',
            'verified_purchase' => true,
        ])->assertOk();

        $this->assertSame([
            'message' => 'success',
            'isSuccess' => true,
            'error' => null,
            'isFailure' => false,
        ], $response->json());
        $review = OnlineStoreReview::query()->firstOrFail();
        $this->assertSame($user->id, $review->user_id);
        $this->assertSame($customer->id, $review->customer_id);
        $this->assertSame(OnlineStoreReview::STATUS_PENDING, $review->status);
        $this->assertFalse($review->is_verified_purchase);
    }

    public function test_published_success_rows_keep_legacy_field_names_and_envelope(): void
    {
        [$user, , $token] = $this->linkedCustomerToken();
        $product = OnlineStoreFixtureFactory::createProduct(['nameAr' => 'Legacy fixture product']);
        $review = app(OnlineStoreReviewService::class)->submit($user, $product, ['rating' => 4, 'comment' => 'visible fixture']);
        $review->forceFill(['status' => OnlineStoreReview::STATUS_PUBLISHED])->save();

        $response = $this->postJson('/Comments/GetAllCommentsToItem?ItemId='.$product->id, [
            'paginationInfo' => ['pageIndex' => 0, 'pageSize' => 0],
        ])->assertOk();

        $payload = $response->json();
        $this->assertSame(['rows', 'total', 'totalNotFiltered'], array_keys($payload));
        $this->assertSame(1, $payload['total']);
        $this->assertSame(1, $payload['totalNotFiltered']);
        $this->assertSame(
            ['id', 'comment', 'productId', 'productName', 'rate', 'userName', 'userAddId', 'isShow', 'dateAdd'],
            array_keys($payload['rows'][0])
        );
        $this->assertSame($product->id, $payload['rows'][0]['productId']);
        $this->assertSame('Legacy fixture product', $payload['rows'][0]['productName']);
        $this->assertTrue($payload['rows'][0]['isShow']);

        $this->withToken($token)->postJson('/Comments/ManageComment', [
            'id' => $review->id,
            'comment' => 'edited pending fixture',
            'productId' => $product->id,
            'rate' => 3,
        ])->assertNotFound();
    }

    /** @return array{0: \App\Models\User, 1: \App\Models\Customer, 2: string} */
    private function linkedCustomerToken(): array
    {
        $user = OnlineStoreFixtureFactory::createStoreActor();
        $customer = OnlineStoreFixtureFactory::createCustomer();
        app(StoreIdentityService::class)->save(
            OnlineStoreFixtureFactory::createAdminActor(),
            $user,
            ['role' => 'customer', 'customer_id' => $customer->id, 'account_source' => 'store_app', 'status' => 'active']
        );
        $token = StoreUser::query()->findOrFail($user->id)->createToken('legacy-comments-fixture')->plainTextToken;

        return [$user, $customer, $token];
    }
}
