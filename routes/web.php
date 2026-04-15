<?php

use App\Http\Controllers\API\EmployeeDetails;
use App\Http\Controllers\API\EmployeeTasks;
use App\Http\Controllers\API\Products;
use App\Http\Controllers\API\Stocks;
use App\Http\Controllers\API\Test;
use App\Http\Controllers\ProductEditTestController;
use App\Http\Controllers\StoreSyncTestController;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});

/** اختبار مزامنة المخزون مع متجر .NET — راجع الصفحة والتحذير داخلها */
Route::get('/test/store-sync', [StoreSyncTestController::class, 'show'])->name('test.store-sync');
Route::post('/test/store-sync', [StoreSyncTestController::class, 'run'])->name('test.store-sync.run');

/** إضافة منتج جديد (محلي ثم متجر عبر syncNewProductToStore) */
Route::get('/test/product-create', [ProductEditTestController::class, 'create'])->name('test.product-create');
Route::post('/test/product-create', [ProductEditTestController::class, 'createRun'])->name('test.product-create.run');

/**
 * ربط public/storage → storage/app/public (مرة واحدة على السيرفر).
 * الإنتاج: deploy_once.php معطّل؛ استخدم: /test/run-storage-link?token=TOKEN
 * TOKEN نفس DEPLOY_ONCE_TOKEN أو القيمة الافتراضية في deploy_once.php
 */
Route::get('/test/run-storage-link', function () {
    $token = (string) request()->query('token', '');
    $expected = (string) env('DEPLOY_ONCE_TOKEN', 'eshterelyDeploy2026SecureToken123');
    if ($token === '' || ! hash_equals($expected, $token)) {
        abort(403);
    }
    $linkPath = public_path('storage');
    if (file_exists($linkPath)) {
        return response(
            "OK: الرابط موجود مسبقاً:\n{$linkPath}\n",
            200,
            ['Content-Type' => 'text/plain; charset=UTF-8']
        );
    }
    $exit = Artisan::call('storage:link');

    return response(
        Artisan::output()."\nExit code: {$exit}\n",
        200,
        ['Content-Type' => 'text/plain; charset=UTF-8']
    );
})->name('test.run-storage-link');

/** اختبار تعديل منتج محلياً ثم مزامنة المتجر (syncProductEditToStore) */
Route::get('/test/product-edit', [ProductEditTestController::class, 'show'])->name('test.product-edit');
Route::post('/test/product-edit', [ProductEditTestController::class, 'run'])->name('test.product-edit.run');
Route::post('/test/product-edit/delete-image', [ProductEditTestController::class, 'deleteImage'])->name('test.product-edit.delete-image');
Route::post('/test/product-edit/delete-product', [ProductEditTestController::class, 'deleteProduct'])->name('test.product-edit.delete-product');

/** جدول المنتجات (DataTables) + JSON للخادم */
Route::get('/test/products-list', [ProductEditTestController::class, 'productsList'])->name('test.products-list');
Route::get('/test/products-list/data', [ProductEditTestController::class, 'productsData'])->name('test.products-list.data');

Route::get('/test/tasks', [EmployeeTasks::class, 'getCompletedTasks']);

Auth::routes();

Route::get('/home', [App\Http\Controllers\HomeController::class, 'index'])->name('home');
Route::get('/edit/{id}', [Test::class, 'edit']);
Route::put('/update/{id}', [Test::class, 'update'])->name('projects.update');

// Route::get('/test/per', [EmployeeDetails::class, 'viewTest']);
Route::get('/test/store/products', [Products::class, 'importAllProducts']);

// main categories
Route::get('/store/shown/main/categories', [Products::class, 'storeShownMainCategories']);
Route::get('/store/unshown/main/categories', [Products::class, 'storeUnshownMainCategories']);

// sub categories
Route::get('/store/sub/categories', [Products::class, 'storeSubCategories']);

Route::get('/test/products', [Test::class, 'importAllProducts']);

//  Route::get('/max' , [Stocks::class,'maxExc']);
