<?php

namespace Tests\Feature\OnlineStore;

use App\Models\AdminNotification;
use App\Models\NormalImageProduct;
use App\Models\OnlineStore\OnlineStoreListing;
use App\Models\OnlineStore\OnlineStoreReview;
use App\Models\Store\StoreCategory;
use App\Models\Store\StoreShiplyCity;
use App\Models\Store\StoreShiplyVillage;
use App\Models\Store\StoreSubCategory;
use App\Models\Store\StoreUser;
use App\Models\StoreSection;
use App\Services\OnlineStore\OnlineStoreNotificationService;
use App\Services\OnlineStore\StoreIdentityService;
use App\Services\ShiplyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Mockery;
use Tests\Support\OnlineStoreFixtureFactory;
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

    public function test_auth_login_check_user_and_owner_user_crud_success_contracts(): void
    {
        $password = 'legacy-contract-password';
        $owner = OnlineStoreFixtureFactory::createStoreActor([
            'name' => 'Legacy Owner',
            'email' => 'legacy-owner-'.Str::uuid().'@example.invalid',
            'password' => Hash::make($password),
            'phone' => '0590000000',
        ]);

        $login = $this->postJson('/Auth/login', [
            'email' => $owner->email,
            'password' => $password,
            'userToken' => 'legacy-fcm-token',
        ])->assertOk()->assertJsonStructure([
            'user' => $this->userPayloadKeys(),
            'token',
        ]);
        $this->assertSame((string) $owner->id, $login->json('user.id'));
        $token = (string) $login->json('token');

        $this->postJson('/Auth/CheckUser?UserId='.$owner->id)
            ->assertOk()->assertJsonStructure($this->userPayloadKeys())
            ->assertJsonPath('id', (string) $owner->id);
        $this->withToken($token)->postJson('/Users/GetById', ['id' => $owner->id])
            ->assertOk()->assertJsonPath('fullName', 'Legacy Owner');
        $this->withToken($token)->postJson('/Users/Edit', [
            'id' => $owner->id,
            'fullName' => 'Legacy Owner Edited',
            'phoneNumber2' => '0560000000',
        ])->assertOk()
            ->assertJsonPath('fullName', 'Legacy Owner Edited')
            ->assertJsonPath('phoneNumber2', '0560000000');

        $registeredEmail = 'legacy-register-'.Str::uuid().'@example.invalid';
        $this->postJson('/Users/Register', [
            'email' => $registeredEmail,
            'phoneNumber' => '0591111111',
            'password' => $password,
            'confirmPassword' => $password,
        ])->assertOk()
            ->assertJsonStructure($this->userPayloadKeys())
            ->assertJsonPath('email', $registeredEmail)
            ->assertJsonPath('typeUser', 'User')
            ->assertJsonPath('accountRoles', []);
    }

    public function test_legacy_user_payload_exposes_only_authoritative_active_verified_account_roles(): void
    {
        $admin = OnlineStoreFixtureFactory::createAdminActor();
        $password = 'legacy-account-role-password';

        $customerUser = OnlineStoreFixtureFactory::createStoreActor([
            'email' => 'customer-role-'.Str::uuid().'@example.invalid',
            'password' => Hash::make($password),
        ]);
        app(StoreIdentityService::class)->save($admin, $customerUser, [
            'role' => 'customer', 'customer_id' => OnlineStoreFixtureFactory::createCustomer()->id,
            'account_source' => 'store_app', 'status' => 'active',
        ]);
        $customerLogin = $this->postJson('/Auth/login', [
            'email' => $customerUser->email, 'password' => $password,
        ])->assertOk()
            ->assertJsonPath('user.typeUser', 'User')
            ->assertJsonPath('user.accountRoles', ['customer']);
        $customerToken = (string) $customerLogin->json('token');
        $this->withToken($customerToken)->postJson('/Users/GetById', ['id' => $customerUser->id])
            ->assertOk()->assertJsonPath('accountRoles', ['customer']);
        $this->withToken($customerToken)->postJson('/Users/Edit', [
            'id' => $customerUser->id, 'fullName' => 'Customer Role User',
        ])->assertOk()->assertJsonPath('accountRoles', ['customer']);

        $sellerUser = OnlineStoreFixtureFactory::createStoreActor();
        app(StoreIdentityService::class)->save($admin, $sellerUser, [
            'role' => 'seller', 'seller_id' => OnlineStoreFixtureFactory::createSeller()->id,
            'account_source' => 'store_app', 'status' => 'active',
        ]);
        $this->postJson('/Auth/CheckUser?UserId='.$sellerUser->id)->assertOk()
            ->assertJsonPath('typeUser', 'User')
            ->assertJsonPath('accountRoles', ['seller']);

        $dualUser = OnlineStoreFixtureFactory::createStoreActor();
        app(StoreIdentityService::class)->save($admin, $dualUser, [
            'role' => 'seller', 'seller_id' => OnlineStoreFixtureFactory::createSeller()->id,
            'account_source' => 'store_app', 'status' => 'active',
        ]);
        app(StoreIdentityService::class)->save($admin, $dualUser, [
            'role' => 'customer', 'customer_id' => OnlineStoreFixtureFactory::createCustomer()->id,
            'account_source' => 'store_app', 'status' => 'active',
        ]);
        $this->postJson('/Auth/CheckUser?UserId='.$dualUser->id)->assertOk()
            ->assertJsonPath('accountRoles', ['customer', 'seller']);

        $pendingUser = OnlineStoreFixtureFactory::createStoreActor();
        app(StoreIdentityService::class)->save($admin, $pendingUser, [
            'role' => 'customer', 'customer_id' => OnlineStoreFixtureFactory::createCustomer()->id,
            'account_source' => 'store_app', 'status' => 'pending',
        ]);
        $this->postJson('/Auth/CheckUser?UserId='.$pendingUser->id)->assertOk()
            ->assertJsonPath('accountRoles', []);

        $canceledUser = OnlineStoreFixtureFactory::createStoreActor();
        $canceledSeller = OnlineStoreFixtureFactory::createSeller();
        app(StoreIdentityService::class)->save($admin, $canceledUser, [
            'role' => 'seller', 'seller_id' => $canceledSeller->id,
            'account_source' => 'store_app', 'status' => 'active',
        ]);
        $canceledSeller->forceFill(['is_canceled' => true])->save();
        $this->postJson('/Auth/CheckUser?UserId='.$canceledUser->id)->assertOk()
            ->assertJsonPath('accountRoles', []);
    }

    public function test_settings_ads_and_notification_success_and_empty_envelopes_are_frozen(): void
    {
        $this->postJson('/Settings/CheckSetting')->assertOk()->assertJsonStructure([
            'data' => ['id', 'isClose', 'message', 'call', 'whatsApp', 'instagram', 'twitter'],
            'isSuccess', 'error', 'isFailure',
        ])->assertJsonPath('isSuccess', true)->assertJsonPath('isFailure', false);

        // Ads are an approved compatibility stub until a persisted legacy source exists.
        $this->postJson('/OnlineAds/GetAllAds')->assertOk()
            ->assertExactJson(['rows' => [], 'total' => 0, 'totalNotFiltered' => 0]);
        $this->postJson('/Notifications/GetNotifications')->assertOk()
            ->assertExactJson(['rows' => [], 'total' => 0, 'totalNotFiltered' => 0]);

        [$owner, , $token] = $this->linkedCustomerToken();
        $notification = AdminNotification::query()->forceCreate([
            'type' => OnlineStoreNotificationService::TYPE_ORDER_STATUS,
            'title' => 'Legacy notification title',
            'body' => 'Legacy notification body',
            'recipient_user_id' => $owner->id,
            'data' => ['destination_type' => 'order', 'destination_id' => '77'],
            'is_read' => false,
        ]);

        $response = $this->withToken($token)->postJson('/Notifications/GetNotifications', ['userId' => $owner->id])
            ->assertOk();
        $this->assertSame(['rows', 'total', 'totalNotFiltered'], array_keys($response->json()));
        $this->assertSame(
            ['id', 'isRead', 'title', 'content', 'toUser', 'createdAt', 'updatedAt'],
            array_keys($response->json('rows.0'))
        );
        $this->assertSame($notification->id, $response->json('rows.0.id'));
    }

    public function test_comment_listing_and_authenticated_manage_contracts_are_frozen(): void
    {
        [$owner, , $token] = $this->linkedCustomerToken();
        $product = OnlineStoreFixtureFactory::createProduct(['nameAr' => 'Legacy reviewed product']);

        $manage = $this->withToken($token)->postJson('/Comments/ManageComment', [
            'id' => 0,
            'productId' => $product->id,
            'rate' => 5,
            'comment' => 'Legacy review',
        ])->assertOk();
        $manage->assertExactJson([
            'message' => 'success', 'isSuccess' => true, 'error' => null, 'isFailure' => false,
        ]);

        $review = OnlineStoreReview::query()->where('user_id', $owner->id)->firstOrFail();
        $review->forceFill(['status' => OnlineStoreReview::STATUS_PUBLISHED])->save();
        $listing = $this->postJson('/Comments/GetAllCommentsToItem', ['ItemId' => $product->id])->assertOk();
        $this->assertSame(['rows', 'total', 'totalNotFiltered'], array_keys($listing->json()));
        $this->assertSame(
            ['id', 'comment', 'productId', 'productName', 'rate', 'userName', 'userAddId', 'isShow', 'dateAdd'],
            array_keys($listing->json('rows.0'))
        );
        $listing->assertJsonPath('rows.0.productId', $product->id)
            ->assertJsonPath('rows.0.userAddId', (string) $owner->id)
            ->assertJsonPath('rows.0.isShow', true);
    }

    public function test_category_and_item_list_detail_empty_and_not_found_contracts_are_frozen(): void
    {
        $section = StoreSection::query()->create([
            'name' => 'Legacy Section', 'description' => 'Legacy description', 'sort_order' => 1, 'is_active' => true,
        ]);
        $category = StoreCategory::query()->forceCreate([
            'nameAr' => 'Legacy Category', 'nameEng' => 'Legacy Category', 'isShow' => true,
        ]);
        $subCategory = StoreSubCategory::query()->forceCreate([
            'nameAr' => 'Legacy Subcategory', 'nameEng' => 'Legacy Subcategory',
            'isShow' => true, 'mainCategoryId' => $category->id,
        ]);
        $product = OnlineStoreFixtureFactory::createProduct([
            'nameAr' => 'Unique Legacy Search Product', 'store_section_id' => $section->id,
        ]);
        $listingId = random_int(1_800_000_000, 1_899_999_999);
        $this->assertNotSame($product->id, $listingId);
        OnlineStoreListing::query()->forceCreate([
            'id' => $listingId,
            'product_id' => $product->id,
            'status' => 'draft',
            'readiness_state' => 'incomplete',
        ]);
        $productWithoutListing = OnlineStoreFixtureFactory::createProduct([
            'nameAr' => 'Unique Legacy Search Product Without Listing',
            'store_section_id' => $section->id,
        ]);
        DB::table('sub_category_products')->insert([
            'product_id' => $product->id,
            'sub_category_id' => $subCategory->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $main = $this->postJson('/MainCategorys/GetAllShowMainCategories')->assertOk();
        $this->assertSame(['rows', 'paginationInfo'], array_keys($main->json()));
        $main->assertJsonFragment(['id' => $section->id, 'nameAr' => 'Legacy Section'])
            ->assertJsonStructure(['rows' => ['*' => ['id', 'nameAr', 'nameEng', 'nameAbree', 'isShow', 'supCategories']]]);

        $sub = $this->postJson('/SupCategorys/GetAllShowSupCategories', ['mainCategoryId' => $category->id])->assertOk();
        $this->assertSame(['rows', 'paginationInfo'], array_keys($sub->json()));
        $sub->assertJsonFragment(['id' => $subCategory->id, 'nameAr' => 'Legacy Subcategory'])
            ->assertJsonStructure(['rows' => ['*' => ['id', 'nameAr', 'nameEng', 'nameAbree', 'mainCategoryId', 'isShow']]]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $search = $this->postJson('/Items/GetAllItemByName', ['Name' => 'Unique Legacy Search'])->assertOk();
        $listingQueries = collect(DB::getQueryLog())
            ->filter(fn (array $query) => str_contains($query['query'], 'online_store_listings'));
        DB::disableQueryLog();
        $this->assertSame(['rows', 'paginationInfo'], array_keys($search->json()));
        $search->assertJsonCount(2, 'rows')
            ->assertJsonFragment(['id' => $product->id, 'listingId' => $listingId])
            ->assertJsonFragment(['id' => $productWithoutListing->id, 'listingId' => null])
            ->assertJsonStructure(['rows' => ['*' => $this->productPayloadKeys()]]);
        $this->assertCount(1, $listingQueries, 'Legacy item lists must eager-load listings in one query.');
        $this->postJson('/Items/GetAllItemsShowByMainCategory', ['MainCategory' => $section->id])
            ->assertOk()->assertJsonFragment(['id' => $product->id, 'listingId' => $listingId]);
        $this->postJson('/Items/GetAllShowItemsBySupCatId', ['supCategoryId' => $subCategory->id])
            ->assertOk()->assertJsonFragment(['id' => $product->id, 'listingId' => $listingId]);
        $this->postJson('/Items/GetItemById', ['itemId' => $product->id])->assertOk()
            ->assertJsonStructure($this->productPayloadKeys())->assertJsonPath('id', $product->id)
            ->assertJsonPath('listingId', $listingId);
        $this->postJson('/Items/GetAllItemByName', ['Name' => 'Unique Legacy Search Product Without Listing'])
            ->assertOk()->assertJsonCount(1, 'rows')
            ->assertJsonPath('rows.0.id', $productWithoutListing->id)
            ->assertJsonPath('rows.0.listingId', null);
        $this->postJson('/Items/GetAllItemByName', ['Name' => '__contract_missing__'])->assertOk()
            ->assertExactJson(['rows' => [], 'paginationInfo' => ['totalRowsCount' => 0, 'totalPagesCount' => 1]]);
        $this->postJson('/Items/GetItemById', ['itemId' => 2147483647])->assertNotFound()
            ->assertExactJson(['message' => 'ThisItemNotFound']);
    }

    public function test_city_village_delivery_success_and_error_contracts_are_frozen(): void
    {
        $shiply = Mockery::mock(ShiplyService::class);
        $shiply->shouldReceive('calculateDeliveryCost')->once()->with(92001, 100.0, 'test')->andReturn([
            'delivery_cost' => 25.0,
            'extra_price' => 1.0,
            'returned_extra_price' => 2.0,
        ]);
        $this->app->instance(ShiplyService::class, $shiply);

        StoreShiplyCity::query()->forceCreate([
            'shiply_id' => 91001, 'name' => 'Legacy City', 'mode' => 'test', 'deleted_at_remote' => null,
        ]);
        StoreShiplyVillage::query()->forceCreate([
            'shiply_id' => 92001, 'shiply_city_id' => 91001, 'name' => 'Legacy Village',
            'mode' => 'test', 'is_closed' => false, 'deleted_at_remote' => null,
        ]);

        $cities = $this->postJson('/Cities/GetAllCities')->assertOk();
        $this->assertSame(['rows', 'paginationInfo'], array_keys($cities->json()));
        $cities->assertJsonFragment(['id' => 91001, 'cityNameAr' => 'Legacy City'])
            ->assertJsonStructure(['rows' => ['*' => ['id', 'cityNameAr', 'cityNameEng', 'cityNameAbree', 'deliver', 'isShow']]]);
        $villages = $this->postJson('/Cities/GetVillagesByCityId', ['cityId' => 91001])->assertOk();
        $this->assertSame(['rows', 'paginationInfo'], array_keys($villages->json()));
        $villages->assertJsonFragment(['id' => 92001, 'name' => 'Legacy Village'])
            ->assertJsonStructure(['rows' => ['*' => ['id', 'name', 'note', 'isClosed']]]);

        $this->postJson('/Cities/CalculateDeliveryFee', ['villageId' => 92001, 'price' => 100])
            ->assertOk()->assertExactJson([
                'deliveryCost' => 25,
                'priceDelivery' => 25,
                'fees' => ['delivery_cost' => 25, 'extra_price' => 1, 'returned_extra_price' => 2],
            ]);
        $this->postJson('/Cities/CalculateDeliveryFee')->assertStatus(400)
            ->assertExactJson(['message' => 'VillageRequired']);
    }

    public function test_legacy_order_create_history_empty_success_and_cancel_contracts_are_frozen(): void
    {
        [$owner, , $token] = $this->linkedCustomerToken();
        $this->withToken($token)->postJson('/Orders/GetAllOrdersByUserId', ['userId' => $owner->id])
            ->assertOk()->assertExactJson([
                'rows' => [], 'paginationInfo' => ['totalRowsCount' => 0, 'totalPagesCount' => 1],
            ]);
        $product = $this->publishedLegacyProduct();

        $created = $this->withToken($token)->postJson('/Orders/ManageOrder', [
            'customerName' => 'Untrusted name',
            'userAddId' => $owner->id + 1000,
            'address' => 'Legacy delivery address',
            'totalPriceWithOutDiscound' => 1,
            'totalPriceWithDiscound' => 1,
            'details' => [[
                'itemId' => $product->id, 'itemSizeId' => null, 'itemSizeColorId' => null,
                'quantity' => 1, 'itemPrice' => 1,
                'totalPriceWithDiscound' => 1, 'totalPriceWithOutDiscound' => 1,
            ]],
        ])->assertOk()->assertJsonStructure($this->orderPayloadKeys());
        $orderId = (int) $created->json('id');
        $created->assertJsonPath('customerId', (string) $owner->id)
            ->assertJsonPath('details.0.itemId', $product->id);

        $history = $this->withToken($token)->postJson('/Orders/GetAllOrdersByUserId', ['userId' => $owner->id])
            ->assertOk();
        $this->assertSame(['rows', 'paginationInfo'], array_keys($history->json()));
        $history->assertJsonPath('rows.0.id', $orderId)
            ->assertJsonStructure(['rows' => ['*' => $this->orderPayloadKeys()]]);

        $this->withToken($token)->postJson('/Orders/CancelOrder', [
            'id' => $orderId, 'userId' => $owner->id,
        ])->assertOk()
            ->assertJsonStructure($this->orderPayloadKeys())
            ->assertJsonPath('id', $orderId)
            ->assertJsonPath('status', 'Canceled');
    }

    /** @return array{0: \App\Models\User, 1: \App\Models\Customer, 2: string} */
    private function linkedCustomerToken(): array
    {
        $owner = OnlineStoreFixtureFactory::createStoreActor();
        $customer = OnlineStoreFixtureFactory::createCustomer(['name' => 'Legacy Contract Customer']);
        app(StoreIdentityService::class)->save(
            OnlineStoreFixtureFactory::createAdminActor(),
            $owner,
            ['role' => 'customer', 'customer_id' => $customer->id, 'account_source' => 'store_app', 'status' => 'active']
        );
        $token = StoreUser::query()->findOrFail($owner->id)->createToken('legacy-contract')->plainTextToken;

        return [$owner, $customer, $token];
    }

    private function publishedLegacyProduct(): \App\Models\Product
    {
        $product = OnlineStoreFixtureFactory::createProduct([
            'nameAr' => 'Legacy order product', 'normailPrice' => 100, 'stock' => 5,
        ]);
        $listing = OnlineStoreListing::query()->forceCreate([
            'product_id' => $product->id, 'status' => 'published', 'readiness_state' => 'complete',
        ]);
        $imageId = random_int(1_700_000_000, 1_799_999_999);
        NormalImageProduct::query()->forceCreate([
            'id' => $imageId, 'itemId' => $product->id, 'imageUrl' => 'fixtures/legacy-contract.jpg',
        ]);
        $categoryId = random_int(1_700_000_000, 1_799_999_999);
        DB::table('online_store_categories')->insert([
            'id' => $categoryId,
            'name_translations' => json_encode(['en' => 'Legacy Contract']),
            'is_active' => true, 'show_on_home' => false, 'sort_order' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('online_store_category_listing')->insert([
            'online_store_category_id' => $categoryId, 'online_store_listing_id' => $listing->id,
            'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('online_store_media_presentations')->insert([
            'online_store_listing_id' => $listing->id, 'source_type' => 'normal_image',
            'source_id' => $imageId, 'is_main' => true, 'is_visible' => true, 'sort_order' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $product;
    }

    /** @return list<string> */
    private function userPayloadKeys(): array
    {
        return [
            'id', 'userName', 'normalizedUserName', 'email', 'normalizedEmail', 'emailConfirmed',
            'passwordHash', 'securityStamp', 'concurrencyStamp', 'phoneNumber', 'phoneNumberConfirmed',
            'twoFactorEnabled', 'lockoutEnd', 'lockoutEnabled', 'accessFailedCount', 'address', 'block',
            'fullName', 'phoneNumber2', 'typeUser', 'accountRoles', 'userToken', 'dateAdd', 'userUpdate', 'dateUpdate',
            'cityId', 'city', 'mainOrders', 'roles',
        ];
    }

    /** @return list<string> */
    private function productPayloadKeys(): array
    {
        return [
            'id', 'listingId', 'nameAr', 'nameEng', 'nameAbree', 'isShow', 'descriptionAr', 'descriptionEng',
            'descriptionAbree', 'videoUrl', 'normailPrice', 'wholesalePrice', 'stock', 'model',
            'isNewItem', 'isMoreSales', 'rate', 'manufactureYear', 'discount', 'userIdAdd', 'dateAdd',
            'userIdUpdate', 'dateUpdate', 'supCategory', 'normalImagesItems', '_3DImagesItems',
            'viewImagesItems', 'itemSizes',
        ];
    }

    /** @return list<string> */
    private function orderPayloadKeys(): array
    {
        return [
            'id', 'serialNumber', 'orderNumber', 'customerId', 'customerName', 'phoneNum1', 'phoneNum2',
            'cityId', 'address', 'status', 'isWholesale', 'priceDelivery', 'totalPriceWithDiscound',
            'totalPriceWithOutDiscound', 'discoundCodeId', 'discoundCodePercent', 'discoundCode',
            'totalPriceWithDiscoundCode', 'userAddId', 'dateAdd', 'userUpdate', 'dateUpdate',
            'latestHandover', 'statusLogs', 'shiplyTracking', 'details',
        ];
    }
}
