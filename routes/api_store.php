<?php

use App\Http\Controllers\API\Store\StoreAuthController;
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
use App\Http\Controllers\API\Store\StoreSettingsController;
use App\Http\Controllers\API\Store\StoreSupCategoryController;
use App\Http\Controllers\API\Store\StoreUsersController;
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
Route::post('/Users/BlockUserAndNotActive', [StoreUsersController::class, 'blockUserAndNotActive']);

Route::post('/Settings/CheckSetting', [StoreSettingsController::class, 'checkSetting']);

Route::get('/OnlineStore/Home', [StoreHomeController::class, 'index']);
Route::post('/OnlineStore/Home', [StoreHomeController::class, 'index']);

Route::post('/OnlineAds/GetAllAds', [StoreOnlineAdsController::class, 'getAllAds']);
Route::post('/Notifications/GetNotifications', [StoreNotificationsController::class, 'getNotifications']);
Route::post('/Notifications/EditNotification', [StoreNotificationsController::class, 'editNotification']);
Route::post('/Comments/GetAllCommentsToItem', [StoreCommentsController::class, 'getAllCommentsToItem']);
Route::post('/Comments/ManageComment', [StoreCommentsController::class, 'manageComment']);
Route::get('/OnlineStore/Reviews', [StoreCommentsController::class, 'own']);
Route::post('/OnlineStore/Reviews', [StoreCommentsController::class, 'submit']);
Route::get('/OnlineStore/Products/{product}/Reviews', [StoreCommentsController::class, 'product']);
Route::get('/OnlineStore/Favorites', [StoreFavoritesController::class, 'index']);
Route::post('/OnlineStore/Favorites/Toggle', [StoreFavoritesController::class, 'toggle']);
Route::post('/OnlineStore/Coupons/Validate', [StoreCouponsController::class, 'validateCoupon']);

Route::post('/MainCategorys/GetAllShowMainCategories', [StoreMainCategoryController::class, 'getAllShowMainCategories']);
Route::post('/SupCategorys/GetAllShowSupCategories', [StoreSupCategoryController::class, 'getAllShowSupCategories']);

Route::post('/Items/GetAllItemIsMoreSales', [StoreItemsController::class, 'getAllItemIsMoreSales']);
Route::post('/Items/GetAllItemByName', [StoreItemsController::class, 'getAllItemByName']);
Route::post('/Items/GetAllItemsShowByMainCategory', [StoreItemsController::class, 'getAllItemsShowByMainCategory']);
Route::post('/Items/GetItemById', [StoreItemsController::class, 'getItemById']);
Route::post('/Items/GetAllShowItemsBySupCatId', [StoreItemsController::class, 'getAllShowItemsBySupCatId']);

Route::post('/Cities/GetAllCities', [StoreCitiesController::class, 'getAllCities']);
Route::post('/Cities/GetVillagesByCityId', [StoreCitiesController::class, 'getVillagesByCityId']);
Route::post('/Cities/CalculateDeliveryFee', [StoreCitiesController::class, 'calculateDeliveryFee']);

Route::post('/Orders/ManageOrder', [StoreOrdersController::class, 'manageOrder']);
Route::post('/OnlineStore/Checkout', [StoreOrdersController::class, 'checkout']);
Route::post('/Orders/CancelOrder', [StoreOrdersController::class, 'cancelOrder']);
Route::post('/Orders/GetAllOrdersByUserId', [StoreOrdersController::class, 'getAllOrdersByUserId']);
