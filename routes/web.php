<?php

use App\Http\Controllers\AdminNotificationWebController;
use App\Http\Controllers\DebtLedgerShareWebController;
use App\Http\Controllers\EmployeeNotificationWebController;
use App\Http\Controllers\API\EmployeeDetails;
use App\Http\Controllers\API\EmployeeTasks;
use App\Http\Controllers\API\Products;
use App\Http\Controllers\API\Stocks;
use App\Http\Controllers\API\Test;
use App\Http\Controllers\ProductEditTestController;
use App\Http\Controllers\CronJobWebController;
use App\Http\Controllers\SmsTestWebController;
use App\Http\Controllers\StoreSyncTestController;
use App\Http\Controllers\UserSessionsWebController;
use App\Http\Controllers\API\FingerprintPushController;
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

/** ZKTeco ADMS / Push (no CSRF — called by fingerprint device) */
Route::match(['GET', 'POST'], '/iclock/cdata', [FingerprintPushController::class, 'iclockCdata']);
Route::match(['GET', 'POST'], '/iclock/getrequest', [FingerprintPushController::class, 'iclockGetRequest']);
Route::match(['GET', 'POST'], '/iclock/devicecmd', [FingerprintPushController::class, 'iclockDevicecmd']);
Route::get('/iclock/test', [FingerprintPushController::class, 'iclockTest']);

Route::get('/debt-ledger/share/{token}', [DebtLedgerShareWebController::class, 'show'])
    ->name('debt-ledger.public-share');

/** اختبار مزامنة المخزون مع متجر .NET — راجع الصفحة والتحذير داخلها */
/** إرسال إشعار تجريبي للأدمن (قاعدة البيانات + FCM) */
Route::get('/test/admin-notify', [AdminNotificationWebController::class, 'show'])->name('test.admin-notify');
Route::post('/test/admin-notify', [AdminNotificationWebController::class, 'send'])->name('test.admin-notify.send');
Route::get('/test/admin-notify/fcm-test', [AdminNotificationWebController::class, 'fcmTest'])->name('test.admin-notify.fcm-test');
Route::post('/test/admin-notify/fcm-test', [AdminNotificationWebController::class, 'fcmTestWithToken'])->name('test.admin-notify.fcm-test.post');

/** إرسال إشعار تجريبي للموظفين (قاعدة البيانات + FCM) */
Route::get('/test/employee-notify', [EmployeeNotificationWebController::class, 'show'])->name('test.employee-notify');
Route::post('/test/employee-notify', [EmployeeNotificationWebController::class, 'send'])->name('test.employee-notify.send');
Route::get('/test/employee-notify/fcm-test', [EmployeeNotificationWebController::class, 'fcmTest'])->name('test.employee-notify.fcm-test');
Route::post('/test/employee-notify/fcm-test', [EmployeeNotificationWebController::class, 'fcmTestWithToken'])->name('test.employee-notify.fcm-test.post');

/** اختبار إرسال SMS عبر Twilio */
Route::get('/test/sms', [SmsTestWebController::class, 'show'])->name('test.sms');
Route::post('/test/sms', [SmsTestWebController::class, 'send'])->name('test.sms.send');

Route::get('/test/store-sync', [StoreSyncTestController::class, 'show'])->name('test.store-sync');
Route::post('/test/store-sync', [StoreSyncTestController::class, 'run'])->name('test.store-sync.run');

/** تشغيل أوامر الكرون يدوياً وعرض السجلات */
Route::get('/test/cron-jobs', [CronJobWebController::class, 'index'])->name('test.cron-jobs');
Route::post('/test/cron-jobs/run', [CronJobWebController::class, 'run'])->name('test.cron-jobs.run');
Route::get('/test/cron-jobs/log/{id}', [CronJobWebController::class, 'showLog'])->name('test.cron-jobs.log');

/** إدارة جلسات الموظفين والمدراء */
Route::get('/test/user-sessions', [UserSessionsWebController::class, 'index'])->name('test.user-sessions');
Route::post('/test/user-sessions/logout-all-staff', [UserSessionsWebController::class, 'logoutAllStaff'])->name('test.user-sessions.logout-all-staff');
Route::get('/test/user-sessions/{user}', [UserSessionsWebController::class, 'show'])->name('test.user-sessions.show');
Route::post('/test/user-sessions/{user}/logout-all', [UserSessionsWebController::class, 'logoutAll'])->name('test.user-sessions.logout-all');
Route::post('/test/user-sessions/tokens/{token}/revoke', [UserSessionsWebController::class, 'revokeToken'])->name('test.user-sessions.revoke');
Route::post('/test/user-sessions/{user}/password', [UserSessionsWebController::class, 'changePassword'])->name('test.user-sessions.password');

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

/**
 * مسح كل بيانات الحضور على السيرفر (مرة واحدة).
 * GET /test/purge-attendance-data?token=TOKEN&confirm=yes
 */
Route::get('/test/purge-attendance-data', function () {
    $token = (string) request()->query('token', '');
    $expected = (string) env('DEPLOY_ONCE_TOKEN', 'eshterelyDeploy2026SecureToken123');
    if ($token === '' || ! hash_equals($expected, $token)) {
        abort(403);
    }
    $confirm = strtolower(trim((string) request()->query('confirm', '')));
    if (! in_array($confirm, ['yes', '1', 'true'], true)) {
        return response(
            "Add &confirm=yes to execute.\nExample: /test/purge-attendance-data?token=...&confirm=yes\n",
            400,
            ['Content-Type' => 'text/plain; charset=UTF-8']
        );
    }

    $lockPath = storage_path('framework/attendance_purge_once.lock');
    if (is_file($lockPath)) {
        return response(
            "Already executed.\nLock: {$lockPath}\n\n".(file_get_contents($lockPath) ?: ''),
            200,
            ['Content-Type' => 'text/plain; charset=UTF-8']
        );
    }

    $before = [];
    foreach (['employee_attendances', 'employee_attendance_scans', 'fingerprint_raw_logs'] as $table) {
        $before[$table] = (int) \Illuminate\Support\Facades\DB::table($table)->count();
    }

    $exit = Artisan::call('attendance:purge-all', ['--force' => true]);
    $output = Artisan::output();

    $after = [];
    foreach (array_keys($before) as $table) {
        $after[$table] = (int) \Illuminate\Support\Facades\DB::table($table)->count();
    }

    file_put_contents($lockPath, json_encode([
        'executed_at' => now()->toIso8601String(),
        'before' => $before,
        'after' => $after,
        'via' => 'web-route',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    $body = "=== Purge attendance (one-time) ===\nBefore: ".json_encode($before)."\n\n{$output}\nAfter: ".json_encode($after)."\nExit: {$exit}\n";

    return response($body, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
})->name('test.purge-attendance-data');

/**
 * مسح بيانات قسم الطلبيات عند الانتقال للتشغيل الحقيقي.
 * GET /test/purge-sales-orders?token=TOKEN
 * GET /test/purge-sales-orders?token=TOKEN&confirm=yes
 */
Route::get('/test/purge-sales-orders', function () {
    $token = (string) request()->query('token', '');
    $expected = (string) env('DEPLOY_ONCE_TOKEN', 'eshterelyDeploy2026SecureToken123');
    if ($token === '' || ! hash_equals($expected, $token)) {
        abort(403);
    }

    $tables = [
        'sales_return_items',
        'sales_returns',
        'sales_order_shiply_events',
        'sales_order_deliveries',
        'sales_order_media',
        'sales_order_status_logs',
        'sales_order_items',
        'sales_order_packages',
        'sales_orders',
    ];

    $existingTables = array_values(array_filter(
        $tables,
        fn ($table) => \Illuminate\Support\Facades\Schema::hasTable($table)
    ));

    $before = [];
    foreach ($existingTables as $table) {
        $before[$table] = (int) \Illuminate\Support\Facades\DB::table($table)->count();
    }
    if (
        \Illuminate\Support\Facades\Schema::hasTable('instant_sales')
        && \Illuminate\Support\Facades\Schema::hasColumn('instant_sales', 'sales_order_id')
    ) {
        $before['instant_sales_linked_to_sales_orders'] = (int) \Illuminate\Support\Facades\DB::table('instant_sales')
            ->whereNotNull('sales_order_id')
            ->count();
    }

    $confirm = strtolower(trim((string) request()->query('confirm', '')));
    if (! in_array($confirm, ['yes', '1', 'true'], true)) {
        return view('purge-sales-orders', [
            'status' => 'preview',
            'before' => $before,
            'after' => null,
            'token' => $token,
            'lockPath' => null,
        ]);
    }

    $lockPath = storage_path('framework/sales_orders_purge_once.lock');
    if (is_file($lockPath) && request()->query('force') !== 'yes') {
        $lock = json_decode((string) file_get_contents($lockPath), true) ?: [];

        return view('purge-sales-orders', [
            'status' => 'locked',
            'before' => $lock['before'] ?? $before,
            'after' => $lock['after'] ?? null,
            'token' => $token,
            'lockPath' => $lockPath,
            'executedAt' => $lock['executed_at'] ?? null,
        ]);
    }

    \Illuminate\Support\Facades\DB::transaction(function () use ($existingTables) {
        if (
            \Illuminate\Support\Facades\Schema::hasTable('instant_sales')
            && \Illuminate\Support\Facades\Schema::hasColumn('instant_sales', 'sales_order_id')
        ) {
            \Illuminate\Support\Facades\DB::table('instant_sales')->whereNotNull('sales_order_id')->update([
                'sales_order_id' => null,
            ]);
        }

        \Illuminate\Support\Facades\DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach ($existingTables as $table) {
                \Illuminate\Support\Facades\DB::table($table)->delete();
                \Illuminate\Support\Facades\DB::statement("ALTER TABLE `{$table}` AUTO_INCREMENT = 1");
            }
        } finally {
            \Illuminate\Support\Facades\DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
    });

    $after = [];
    foreach ($existingTables as $table) {
        $after[$table] = (int) \Illuminate\Support\Facades\DB::table($table)->count();
    }
    if (
        \Illuminate\Support\Facades\Schema::hasTable('instant_sales')
        && \Illuminate\Support\Facades\Schema::hasColumn('instant_sales', 'sales_order_id')
    ) {
        $after['instant_sales_linked_to_sales_orders'] = (int) \Illuminate\Support\Facades\DB::table('instant_sales')
            ->whereNotNull('sales_order_id')
            ->count();
    }

    file_put_contents($lockPath, json_encode([
        'executed_at' => now()->toIso8601String(),
        'before' => $before,
        'after' => $after,
        'via' => 'web-route',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    return view('purge-sales-orders', [
        'status' => 'done',
        'before' => $before,
        'after' => $after,
        'token' => $token,
        'lockPath' => $lockPath,
        'executedAt' => now()->toIso8601String(),
    ]);
})->name('test.purge-sales-orders');

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
