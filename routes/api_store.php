<?php

use App\Http\Controllers\API\Store\StoreAuthController;
use App\Http\Controllers\API\Store\StoreAddressesController;
use App\Http\Controllers\API\Store\StoreCitiesController;
use App\Http\Controllers\API\Store\StoreCommentsController;
use App\Http\Controllers\API\Store\StoreCouponsController;
use App\Http\Controllers\API\Store\StoreFavoritesController;
use App\Http\Controllers\API\Store\StoreHomeController;
use App\Http\Controllers\API\Store\StoreItemsController;
use App\Http\Controllers\API\Store\StoreMainCategoryController;
use App\Http\Controllers\API\Store\StoreNotificationsController;
use App\Http\Controllers\API\Store\StoreOnlineAdsController;
use App\Http\Controllers\API\Store\StoreOrdersController;
use App\Http\Controllers\API\Store\StorePopupCampaignController;
use App\Http\Controllers\API\Store\StoreSettingsController;
use App\Http\Controllers\API\Store\StoreSupportConversationController;
use App\Http\Controllers\API\Store\StoreSupCategoryController;
use App\Http\Controllers\API\Store\StoreUsersController;
use App\Http\Middleware\RecordOnlineStoreAnalytics;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Store compatibility API
|--------------------------------------------------------------------------
|
| Routes in this file intentionally mirror the legacy ASP.NET store API used
| by the Flutter customer/store app. Keep this layer isolated from the staff
| app routes in routes/api.php.
|
*/

Route::post('/Auth/login', [StoreAuthController::class, 'login']);
Route::post('/Auth/CheckUser', [StoreAuthController::class, 'checkUser']);
Route::post('/Auth/ForgotPassword', [StoreAuthController::class, 'forgotPassword']);
Route::post('/Auth/VerifyForgotPasswordOtp', [StoreAuthController::class, 'verifyForgotPasswordOtp']);
Route::post('/Auth/ChangePassword', [StoreAuthController::class, 'changePassword']);
Route::patch('/Auth/ChangePasswordToForgot', [StoreAuthController::class, 'changePasswordToForgot']);

Route::post('/Users/Register', [StoreUsersController::class, 'register']);
Route::post('/Users/GetById', [StoreUsersController::class, 'getById']);
Route::post('/Users/Edit', [StoreUsersController::class, 'edit']);
Route::post('/Users/ProfileImage', [StoreUsersController::class, 'profileImage']);
Route::post('/Users/FcmToken', [StoreUsersController::class, 'updateFcmToken']);
Route::post('/Users/BlockUserAndNotActive', [StoreUsersController::class, 'blockUserAndNotActive']);

Route::post('/Settings/CheckSetting', [StoreSettingsController::class, 'checkSetting']);

Route::get('/OnlineStore/Home', [StoreHomeController::class, 'index'])
    ->middleware(RecordOnlineStoreAnalytics::class.':store_visits');
Route::post('/OnlineStore/Home', [StoreHomeController::class, 'index'])
    ->middleware(RecordOnlineStoreAnalytics::class.':store_visits');

Route::post('/OnlineAds/GetAllAds', [StoreOnlineAdsController::class, 'getAllAds']);
Route::post('/OnlineStore/Banners/{banner}/Click', [StoreOnlineAdsController::class, 'recordClick'])
    ->whereNumber('banner')
    ->middleware(['throttle:60,1', RecordOnlineStoreAnalytics::class.':banner_clicks']);
Route::post('/OnlineStore/PopupCampaigns/{popupCampaign}/Event', [StorePopupCampaignController::class, 'event'])
    ->whereNumber('popupCampaign')
    ->middleware('throttle:120,1');
Route::post('/Notifications/GetNotifications', [StoreNotificationsController::class, 'getNotifications']);
Route::post('/Notifications/EditNotification', [StoreNotificationsController::class, 'editNotification']);
Route::get('/OnlineStore/Support/Conversations/UnreadCount', [StoreSupportConversationController::class, 'unreadCount']);
Route::get('/OnlineStore/Support/Conversations', [StoreSupportConversationController::class, 'index']);
Route::post('/OnlineStore/Support/Conversations', [StoreSupportConversationController::class, 'store'])
    ->middleware('throttle:20,1');
Route::get('/OnlineStore/Support/Conversations/{conversation}', [StoreSupportConversationController::class, 'show'])
    ->whereNumber('conversation');
Route::post('/OnlineStore/Support/Conversations/{conversation}/Messages', [StoreSupportConversationController::class, 'sendMessage'])
    ->whereNumber('conversation')
    ->middleware('throttle:60,1');
Route::post('/OnlineStore/Support/Conversations/{conversation}/Read', [StoreSupportConversationController::class, 'markRead'])
    ->whereNumber('conversation');
Route::post('/OnlineStore/Support/Conversations/{conversation}/Typing', [StoreSupportConversationController::class, 'typing'])
    ->whereNumber('conversation')
    ->middleware('throttle:120,1');
Route::post('/Comments/GetAllCommentsToItem', [StoreCommentsController::class, 'getAllCommentsToItem']);
Route::post('/Comments/ManageComment', [StoreCommentsController::class, 'manageComment']);
Route::get('/OnlineStore/Reviews', [StoreCommentsController::class, 'own']);
Route::post('/OnlineStore/Reviews', [StoreCommentsController::class, 'submit']);
Route::get('/OnlineStore/Products/{product}/Reviews', [StoreCommentsController::class, 'product']);
Route::get('/OnlineStore/Favorites', [StoreFavoritesController::class, 'index']);
Route::post('/OnlineStore/Favorites/Toggle', [StoreFavoritesController::class, 'toggle']);
Route::post('/OnlineStore/Coupons/Validate', [StoreCouponsController::class, 'validateCoupon']);
Route::get('/OnlineStore/Addresses', [StoreAddressesController::class, 'index']);
Route::post('/OnlineStore/Addresses', [StoreAddressesController::class, 'store']);
Route::post('/OnlineStore/Addresses/Update', [StoreAddressesController::class, 'update']);
Route::post('/OnlineStore/Addresses/Delete', [StoreAddressesController::class, 'destroy']);

Route::post('/MainCategorys/GetAllShowMainCategories', [StoreMainCategoryController::class, 'getAllShowMainCategories']);
Route::post('/SupCategorys/GetAllShowSupCategories', [StoreSupCategoryController::class, 'getAllShowSupCategories']);

Route::post('/Items/GetAllItemIsMoreSales', [StoreItemsController::class, 'getAllItemIsMoreSales']);
Route::post('/Items/GetAllItemByName', [StoreItemsController::class, 'getAllItemByName']);
Route::post('/Items/GetAllItemsShowByMainCategory', [StoreItemsController::class, 'getAllItemsShowByMainCategory']);
Route::post('/Items/GetItemById', [StoreItemsController::class, 'getItemById'])
    ->middleware(RecordOnlineStoreAnalytics::class.':product_views');
Route::post('/Items/GetAllShowItemsBySupCatId', [StoreItemsController::class, 'getAllShowItemsBySupCatId']);

Route::post('/Cities/GetAllCities', [StoreCitiesController::class, 'getAllCities']);
Route::post('/Cities/GetVillagesByCityId', [StoreCitiesController::class, 'getVillagesByCityId']);
Route::post('/Cities/CalculateDeliveryFee', [StoreCitiesController::class, 'calculateDeliveryFee']);

Route::post('/Orders/ManageOrder', [StoreOrdersController::class, 'manageOrder']);
Route::post('/OnlineStore/Checkout', [StoreOrdersController::class, 'checkout']);
Route::post('/Orders/CancelOrder', [StoreOrdersController::class, 'cancelOrder']);
Route::post('/Orders/GetAllOrdersByUserId', [StoreOrdersController::class, 'getAllOrdersByUserId']);
