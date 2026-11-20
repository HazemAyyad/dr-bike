<?php

use App\Http\Controllers\InventoryWebController as Portal;
use App\Http\Middleware\InventoryWebAccess;
use Illuminate\Support\Facades\Route;

Route::prefix('inventory')->name('inventory-web.')->group(function () {
    Route::get('login', [Portal::class, 'loginForm'])->name('login');
    Route::post('login', [Portal::class, 'login'])->middleware('throttle:20,1');
    Route::middleware(InventoryWebAccess::class)->group(function () {
        Route::get('/', [Portal::class, 'index'])->name('index');
        Route::post('logout', [Portal::class, 'logout']);
        Route::get('sessions', [Portal::class, 'sessions']);
        Route::get('products', [Portal::class, 'products']);
        Route::get('products/{product}', [Portal::class, 'product'])->whereNumber('product');
        Route::post('products/{product}/count', [Portal::class, 'saveCount'])->whereNumber('product');
        Route::middleware(InventoryWebAccess::class.':admin')->group(function () {
            Route::post('sessions', [Portal::class, 'createSession']);
            Route::post('products/{product}/approve', [Portal::class, 'approve']);
            Route::post('products/{product}/cost', [Portal::class, 'cost']);
            Route::post('products/{product}/details', [Portal::class, 'updateProduct']);
            Route::post('products/{product}/variants', [Portal::class, 'addVariant']);
            Route::post('products/{product}/variants/{variant}', [Portal::class, 'variant']);
            Route::post('products/{product}/image', [Portal::class, 'image']);
            Route::post('transfers', [Portal::class, 'transfer']);
            Route::get('documents', [Portal::class, 'documents']);
            Route::get('users', [Portal::class, 'users']);
            Route::post('users', [Portal::class, 'saveUser']);
        });
    });
});
