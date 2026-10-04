<?php

namespace Tests\Feature\OnlineStore;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class LegacyStoreContractTest extends TestCase
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

    public function test_frozen_legacy_route_methods_and_paths_remain_registered(): void
    {
        $expected = [
            'POST Auth/login', 'POST Auth/CheckUser', 'POST Auth/ForgotPassword',
            'POST Auth/VerifyForgotPasswordOtp', 'POST Auth/ChangePassword', 'PATCH Auth/ChangePasswordToForgot',
            'POST Users/Register', 'POST Users/GetById', 'POST Users/Edit', 'POST Users/BlockUserAndNotActive',
            'POST Settings/CheckSetting', 'POST OnlineAds/GetAllAds',
            'GET OnlineStore/Home', 'POST OnlineStore/Home',
            'POST Notifications/GetNotifications', 'POST Notifications/EditNotification',
            'POST Comments/GetAllCommentsToItem', 'POST Comments/ManageComment',
            'GET OnlineStore/Reviews', 'POST OnlineStore/Reviews', 'GET OnlineStore/Products/{product}/Reviews',
            'POST MainCategorys/GetAllShowMainCategories', 'POST SupCategorys/GetAllShowSupCategories',
            'POST Items/GetAllItemIsMoreSales', 'POST Items/GetAllItemByName',
            'POST Items/GetAllItemsShowByMainCategory', 'POST Items/GetItemById',
            'POST Items/GetAllShowItemsBySupCatId', 'POST Cities/GetAllCities',
            'POST Cities/GetVillagesByCityId', 'POST Cities/CalculateDeliveryFee',
            'POST Orders/ManageOrder', 'POST OnlineStore/Checkout',
            'POST Orders/CancelOrder', 'POST Orders/GetAllOrdersByUserId',
        ];
        $registered = collect(Route::getRoutes())->flatMap(fn ($route) => collect($route->methods())
            ->reject(fn ($method) => $method === 'HEAD')
            ->map(fn ($method) => $method.' '.$route->uri()))->all();

        foreach ($expected as $route) {
            $this->assertContains($route, $registered);
        }
    }

    public function test_legacy_empty_and_error_envelopes_are_not_normalized_to_admin_api_shapes(): void
    {
        $this->postJson('/OnlineAds/GetAllAds')->assertOk()
            ->assertExactJson(['rows' => [], 'total' => 0, 'totalNotFiltered' => 0]);
        $this->postJson('/Notifications/GetNotifications')->assertOk()
            ->assertExactJson(['rows' => [], 'total' => 0, 'totalNotFiltered' => 0]);
        $this->postJson('/Comments/GetAllCommentsToItem')->assertOk()
            ->assertExactJson(['rows' => [], 'total' => 0, 'totalNotFiltered' => 0]);
        $this->postJson('/Items/GetItemById', ['itemId' => 2147483647])->assertNotFound()
            ->assertExactJson(['message' => 'ThisItemNotFound']);
        $this->postJson('/Cities/CalculateDeliveryFee')->assertStatus(400)
            ->assertExactJson(['message' => 'VillageRequired']);
    }

    public function test_secure_reset_is_the_documented_compatibility_exception(): void
    {
        $this->postJson('/Auth/ForgotPassword', ['Email' => 'legacy@example.invalid'])
            ->assertStatus(426)
            ->assertExactJson([
                'status' => 'upgrade_required',
                'message' => 'A Store app update is required to reset the password securely.',
                'minimum_build' => 10,
            ]);
    }

    public function test_auth_and_user_validation_keep_laravel_validation_envelope_and_legacy_login_error(): void
    {
        $this->postJson('/Auth/login', [])->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);
        $this->postJson('/Users/Register', [])->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'phoneNumber', 'password', 'confirmPassword']);
        $this->postJson('/Auth/login', ['email' => 'missing@example.invalid', 'password' => 'invalid'])
            ->assertStatus(400)->assertExactJson(['message' => 'ErrorInEmailOrPassword']);
    }
}
