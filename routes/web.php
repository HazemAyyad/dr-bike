<?php

use App\Http\Controllers\API\EmployeeDetails;
use App\Http\Controllers\API\EmployeeTasks;
use App\Http\Controllers\API\Products;
use App\Http\Controllers\API\Stocks;
use App\Http\Controllers\API\Test;
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
