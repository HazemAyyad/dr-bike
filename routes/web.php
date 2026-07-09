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
    $hasTable = fn (string $table): bool => \Illuminate\Support\Facades\Schema::hasTable($table);
    $hasColumn = fn (string $table, string $column): bool => $hasTable($table)
        && \Illuminate\Support\Facades\Schema::hasColumn($table, $column);

    $collectDebtImpacts = function () use ($hasTable, $hasColumn): array {
        if (! $hasTable('debt_transactions') || ! $hasColumn('debt_transactions', 'source')) {
            return [];
        }

        $people = \Illuminate\Support\Facades\DB::table('debt_transactions')
            ->select('customer_id', 'seller_id', 'currency')
            ->where('source', 'sales_order')
            ->whereNull('archived_at')
            ->whereNull('deleted_at')
            ->groupBy('customer_id', 'seller_id', 'currency')
            ->get();

        $impacts = [];
        foreach ($people as $person) {
            $currency = $person->currency ?: 'شيكل';
            $base = \Illuminate\Support\Facades\DB::table('debt_transactions')
                ->whereNull('archived_at')
                ->whereNull('deleted_at')
                ->where('currency', $currency);

            if ($person->customer_id) {
                $base->where('customer_id', $person->customer_id)->whereNull('seller_id');
                $name = (string) \Illuminate\Support\Facades\DB::table('customers')
                    ->where('id', $person->customer_id)
                    ->value('name');
                $typeLabel = 'زبون';
            } else {
                $base->where('seller_id', $person->seller_id)->whereNull('customer_id');
                $name = (string) \Illuminate\Support\Facades\DB::table('sellers')
                    ->where('id', $person->seller_id)
                    ->value('name');
                $typeLabel = 'تاجر';
            }

            $currentTaken = (float) (clone $base)->where('type', 'taken')->sum('amount');
            $currentGiven = (float) (clone $base)->where('type', 'given')->sum('amount');
            $removedTaken = (float) (clone $base)->where('source', 'sales_order')->where('type', 'taken')->sum('amount');
            $removedGiven = (float) (clone $base)->where('source', 'sales_order')->where('type', 'given')->sum('amount');

            $currentBalance = $currentTaken - $currentGiven;
            $removedBalance = $removedTaken - $removedGiven;

            $impacts[] = [
                'person_type_label' => $typeLabel,
                'person_id' => (int) ($person->customer_id ?: $person->seller_id),
                'person_name' => $name !== '' ? $name : ('#'.($person->customer_id ?: $person->seller_id)),
                'currency' => $currency,
                'current_balance' => $currentBalance,
                'removed_balance' => $removedBalance,
                'expected_balance' => $currentBalance - $removedBalance,
            ];
        }

        return $impacts;
    };

    $viewData = function (array $data) use ($token): array {
        return array_merge([
            'token' => $token,
            'metricLabels' => [
                'sales_return_items' => 'أصناف المرتجعات',
                'sales_returns' => 'مرتجعات الطلبيات',
                'sales_order_shiply_events' => 'أحداث Shiply للطلبيات',
                'sales_order_deliveries' => 'سجلات تسليم الطلبيات',
                'sales_order_media' => 'صور ووسائط الطلبيات',
                'sales_order_status_logs' => 'سجل حالات الطلبيات',
                'sales_order_items' => 'أصناف الطلبيات',
                'sales_order_packages' => 'بكجات الطلبيات',
                'sales_orders' => 'فواتير الطلبيات',
                'instant_sales_linked_to_sales_orders' => 'فواتير بيع فوري مرتبطة بطلبيات',
                'debt_transactions_sales_order' => 'قيود دفتر الديون الناتجة عن طلبيات',
                'legacy_debts_linked_to_sales_orders' => 'ديون قديمة مرتبطة بطلبيات',
            ],
            'purgeRecords' => [
                'كل صفوف sales_orders: فواتير الطلبيات الرئيسية والفرعية.',
                'كل صفوف sales_order_items و sales_order_packages.',
                'كل صفوف sales_order_status_logs و sales_order_media و sales_order_deliveries و sales_order_shiply_events.',
                'كل صفوف sales_returns و sales_return_items التابعة للطلبيات.',
                'صفوف debt_transactions التي source فيها sales_order فقط.',
                'صفوف debts القديمة المرتبطة بعمود sales_orders.debt_id إن وجدت.',
                'سيتم فصل instant_sales.sales_order_id فقط بدون حذف فواتير البيع الفوري نفسها.',
            ],
        ], $data);
    };

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
    if ($hasColumn('debt_transactions', 'source')) {
        $before['debt_transactions_sales_order'] = (int) \Illuminate\Support\Facades\DB::table('debt_transactions')
            ->where('source', 'sales_order')
            ->count();
    }
    if ($hasColumn('sales_orders', 'debt_id') && $hasTable('debts')) {
        $before['legacy_debts_linked_to_sales_orders'] = (int) \Illuminate\Support\Facades\DB::table('debts')
            ->whereIn('id', function ($query) {
                $query->select('debt_id')
                    ->from('sales_orders')
                    ->whereNotNull('debt_id');
            })
            ->count();
    }

    $debtImpacts = $collectDebtImpacts();

    $confirm = strtolower(trim((string) request()->query('confirm', '')));
    if (! in_array($confirm, ['yes', '1', 'true'], true)) {
        return view('purge-sales-orders', $viewData([
            'status' => 'preview',
            'before' => $before,
            'after' => null,
            'lockPath' => null,
            'debtImpacts' => $debtImpacts,
        ]));
    }

    $lockPath = storage_path('framework/sales_orders_purge_once.lock');
    if (is_file($lockPath) && request()->query('force') !== 'yes') {
        $lock = json_decode((string) file_get_contents($lockPath), true) ?: [];

        return view('purge-sales-orders', $viewData([
            'status' => 'locked',
            'before' => $lock['before'] ?? $before,
            'after' => $lock['after'] ?? null,
            'lockPath' => $lockPath,
            'executedAt' => $lock['executed_at'] ?? null,
            'debtImpacts' => $lock['debt_impacts'] ?? $debtImpacts,
        ]));
    }

    $affectedDebtPeople = [];

    \Illuminate\Support\Facades\DB::transaction(function () use ($existingTables, $hasTable, $hasColumn, &$affectedDebtPeople) {
        $legacyDebtIds = collect();
        if ($hasColumn('sales_orders', 'debt_id') && $hasTable('debts')) {
            $legacyDebtIds = \Illuminate\Support\Facades\DB::table('sales_orders')
                ->whereNotNull('debt_id')
                ->pluck('debt_id')
                ->filter()
                ->unique()
                ->values();
        }

        if ($hasColumn('debt_transactions', 'source')) {
            $affectedDebtPeople = \Illuminate\Support\Facades\DB::table('debt_transactions')
                ->select('customer_id', 'seller_id')
                ->where('source', 'sales_order')
                ->groupBy('customer_id', 'seller_id')
                ->get()
                ->map(fn ($row) => [
                    'customer_id' => $row->customer_id ? (int) $row->customer_id : null,
                    'seller_id' => $row->seller_id ? (int) $row->seller_id : null,
                ])
                ->all();

            \Illuminate\Support\Facades\DB::table('debt_transactions')
                ->where('source', 'sales_order')
                ->delete();
        }

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

            if ($legacyDebtIds->isNotEmpty() && $hasTable('debts')) {
                \Illuminate\Support\Facades\DB::table('debts')->whereIn('id', $legacyDebtIds)->delete();
            }
        } finally {
            \Illuminate\Support\Facades\DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
    });

    foreach ($affectedDebtPeople as $person) {
        app(\App\Services\DebtLedgerService::class)->recalculateBalances(
            $person['customer_id'] ?? null,
            $person['seller_id'] ?? null
        );
    }

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
    if ($hasColumn('debt_transactions', 'source')) {
        $after['debt_transactions_sales_order'] = (int) \Illuminate\Support\Facades\DB::table('debt_transactions')
            ->where('source', 'sales_order')
            ->count();
    }
    if ($hasColumn('sales_orders', 'debt_id') && $hasTable('debts')) {
        $after['legacy_debts_linked_to_sales_orders'] = (int) \Illuminate\Support\Facades\DB::table('debts')
            ->whereIn('id', function ($query) {
                $query->select('debt_id')
                    ->from('sales_orders')
                    ->whereNotNull('debt_id');
            })
            ->count();
    }

    file_put_contents($lockPath, json_encode([
        'executed_at' => now()->toIso8601String(),
        'before' => $before,
        'after' => $after,
        'debt_impacts' => $debtImpacts,
        'via' => 'web-route',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    return view('purge-sales-orders', $viewData([
        'status' => 'done',
        'before' => $before,
        'after' => $after,
        'lockPath' => $lockPath,
        'executedAt' => now()->toIso8601String(),
        'debtImpacts' => $debtImpacts,
    ]));
})->name('test.purge-sales-orders');

/**
 * مسح بيانات عمليات البيع الفوري عند الانتقال للتشغيل الحقيقي.
 * GET /test/purge-instant-sales?token=TOKEN
 * GET /test/purge-instant-sales?token=TOKEN&confirm=yes
 */
Route::get('/test/purge-instant-sales', function () {
    $token = (string) request()->query('token', '');
    $expected = (string) env('DEPLOY_ONCE_TOKEN', 'eshterelyDeploy2026SecureToken123');
    if ($token === '' || ! hash_equals($expected, $token)) {
        abort(403);
    }

    $hasTable = fn (string $table): bool => \Illuminate\Support\Facades\Schema::hasTable($table);
    $hasColumn = fn (string $table, string $column): bool => $hasTable($table)
        && \Illuminate\Support\Facades\Schema::hasColumn($table, $column);

    $collectCounts = function () use ($hasTable, $hasColumn): array {
        $counts = [];

        foreach (['instant_sales', 'suspended_instant_sales'] as $table) {
            if ($hasTable($table)) {
                $counts[$table] = (int) \Illuminate\Support\Facades\DB::table($table)->count();
            }
        }

        if ($hasTable('sales_cancellation_requests')) {
            $counts['sales_cancellation_requests_instant'] = (int) \Illuminate\Support\Facades\DB::table('sales_cancellation_requests')
                ->where('sale_type', 'instant')
                ->count();
        }

        if ($hasColumn('sales_returns', 'instant_sale_id')) {
            $counts['sales_returns_linked_to_instant_sales'] = (int) \Illuminate\Support\Facades\DB::table('sales_returns')
                ->whereNotNull('instant_sale_id')
                ->count();

            if ($hasTable('sales_return_items')) {
                $counts['sales_return_items_for_instant_sales'] = (int) \Illuminate\Support\Facades\DB::table('sales_return_items')
                    ->whereIn('sales_return_id', function ($query) {
                        $query->select('id')
                            ->from('sales_returns')
                            ->whereNotNull('instant_sale_id');
                    })
                    ->count();
            }
        }

        if ($hasColumn('debt_transactions', 'source')) {
            $counts['debt_transactions_instant_sale'] = (int) \Illuminate\Support\Facades\DB::table('debt_transactions')
                ->where('source', 'instant_sale')
                ->count();
        }

        if ($hasColumn('product_stock_movements', 'reference_type')) {
            $counts['product_stock_movements_instant_sale'] = (int) \Illuminate\Support\Facades\DB::table('product_stock_movements')
                ->where('reference_type', 'instant_sale')
                ->count();
        }

        $salesOrderIds = collect();
        if ($hasColumn('sales_orders', 'instant_sale_id')) {
            $salesOrderIds = $salesOrderIds->merge(
                \Illuminate\Support\Facades\DB::table('sales_orders')->whereNotNull('instant_sale_id')->pluck('id')
            );
        }
        if ($hasColumn('instant_sales', 'sales_order_id')) {
            $salesOrderIds = $salesOrderIds->merge(
                \Illuminate\Support\Facades\DB::table('instant_sales')->whereNotNull('sales_order_id')->pluck('sales_order_id')
            );
        }
        if ($hasTable('sales_orders')) {
            $counts['sales_orders_linked_to_instant_sales'] = $salesOrderIds->filter()->unique()->count();
        }

        $maintenanceIds = collect();
        if ($hasColumn('maintenance', 'instant_sale_id')) {
            $maintenanceIds = $maintenanceIds->merge(
                \Illuminate\Support\Facades\DB::table('maintenance')->whereNotNull('instant_sale_id')->pluck('id')
            );
        }
        if ($hasColumn('instant_sales', 'maintenance_id')) {
            $maintenanceIds = $maintenanceIds->merge(
                \Illuminate\Support\Facades\DB::table('instant_sales')->whereNotNull('maintenance_id')->pluck('maintenance_id')
            );
        }
        if ($hasTable('maintenance')) {
            $counts['maintenance_linked_to_instant_sales'] = $maintenanceIds->filter()->unique()->count();
        }

        if ($hasColumn('maintenance_daily_box_logs', 'instant_sale_id')) {
            $counts['maintenance_daily_box_logs_linked_to_instant_sales'] = (int) \Illuminate\Support\Facades\DB::table('maintenance_daily_box_logs')
                ->whereNotNull('instant_sale_id')
                ->count();
        }

        return $counts;
    };

    $collectDebtImpacts = function () use ($hasTable, $hasColumn): array {
        if (! $hasTable('debt_transactions') || ! $hasColumn('debt_transactions', 'source')) {
            return [];
        }

        $people = \Illuminate\Support\Facades\DB::table('debt_transactions')
            ->select('customer_id', 'seller_id', 'currency')
            ->where('source', 'instant_sale')
            ->whereNull('archived_at')
            ->whereNull('deleted_at')
            ->groupBy('customer_id', 'seller_id', 'currency')
            ->get();

        $impacts = [];
        foreach ($people as $person) {
            $currency = $person->currency ?: 'شيكل';
            $base = \Illuminate\Support\Facades\DB::table('debt_transactions')
                ->whereNull('archived_at')
                ->whereNull('deleted_at')
                ->where('currency', $currency);

            if ($person->customer_id) {
                $base->where('customer_id', $person->customer_id)->whereNull('seller_id');
                $name = (string) \Illuminate\Support\Facades\DB::table('customers')
                    ->where('id', $person->customer_id)
                    ->value('name');
                $typeLabel = 'زبون';
            } else {
                $base->where('seller_id', $person->seller_id)->whereNull('customer_id');
                $name = (string) \Illuminate\Support\Facades\DB::table('sellers')
                    ->where('id', $person->seller_id)
                    ->value('name');
                $typeLabel = 'تاجر';
            }

            $currentTaken = (float) (clone $base)->where('type', 'taken')->sum('amount');
            $currentGiven = (float) (clone $base)->where('type', 'given')->sum('amount');
            $removedTaken = (float) (clone $base)->where('source', 'instant_sale')->where('type', 'taken')->sum('amount');
            $removedGiven = (float) (clone $base)->where('source', 'instant_sale')->where('type', 'given')->sum('amount');

            $currentBalance = $currentTaken - $currentGiven;
            $removedBalance = $removedTaken - $removedGiven;

            $impacts[] = [
                'person_type_label' => $typeLabel,
                'person_id' => (int) ($person->customer_id ?: $person->seller_id),
                'person_name' => $name !== '' ? $name : ('#'.($person->customer_id ?: $person->seller_id)),
                'currency' => $currency,
                'current_balance' => $currentBalance,
                'removed_balance' => $removedBalance,
                'expected_balance' => $currentBalance - $removedBalance,
            ];
        }

        return $impacts;
    };

    $viewData = function (array $data) use ($token): array {
        return array_merge([
            'token' => $token,
            'pageTitle' => 'تصفير عمليات البيع الفوري',
            'pageSubtitle' => 'صفحة مخصصة لتجهيز البيع الفوري قبل بداية التشغيل الحقيقي، مع معاينة واضحة قبل التنفيذ.',
            'badge' => 'Doctor Bike / Instant Sales',
            'routeName' => 'test.purge-instant-sales',
            'metricsLabel' => 'عدادات عمليات البيع الفوري',
            'doneTitle' => 'تم تصفير عمليات البيع الفوري',
            'doneMessage' => 'تم مسح عمليات البيع الفوري وفصل روابطها من الطلبيات والصيانة وسجلات الصندوق المرتبطة.',
            'confirmDescription' => 'بعد الضغط على الزر سيتم تشغيل الرابط مع <code>confirm=yes</code> وإنشاء قفل يمنع التنفيذ مرة ثانية بالغلط.',
            'confirmButtonLabel' => 'تصفير البيع الفوري الآن',
            'metricLabels' => [
                'instant_sales' => 'فواتير البيع الفوري',
                'suspended_instant_sales' => 'الفواتير الفورية المعلقة',
                'sales_cancellation_requests_instant' => 'طلبات إلغاء البيع الفوري',
                'sales_returns_linked_to_instant_sales' => 'مرتجعات مرتبطة ببيع فوري',
                'sales_return_items_for_instant_sales' => 'أصناف مرتجعات مرتبطة ببيع فوري',
                'debt_transactions_instant_sale' => 'قيود دفتر الديون الناتجة عن بيع فوري',
                'product_stock_movements_instant_sale' => 'حركات المخزون المرجعية للبيع الفوري',
                'sales_orders_linked_to_instant_sales' => 'فواتير طلبيات ستحذف لأنها مرتبطة ببيع فوري',
                'maintenance_linked_to_instant_sales' => 'فواتير صيانة ستحذف لأنها مرتبطة ببيع فوري',
                'maintenance_daily_box_logs_linked_to_instant_sales' => 'سجلات صندوق الصيانة المرتبطة ببيع فوري',
            ],
            'willPurge' => [
                'فواتير البيع الفوري الرئيسية والفرعية',
                'فواتير الصيانة المرتبطة ببيع فوري مع منتجاتها وسجل حركاتها',
                'فواتير الطلبيات المرتبطة ببيع فوري مع أصنافها وحالتها وتسليمها و Shiply',
                'الفواتير الفورية المعلقة',
                'طلبات إلغاء البيع الفوري',
                'مرتجعات البيع المرتبطة بفواتير فورية',
                'معاملات دفتر الديون التي مصدرها بيع فوري',
                'حركات المخزون المرجعية للبيع الفوري فقط',
            ],
            'purgeRecords' => [
                'كل صفوف جدول instant_sales: الفاتورة الرئيسية وأي أصناف/سطور فرعية مرتبطة بها عبر parent_id.',
                'كل صفوف جدول suspended_instant_sales: الفواتير الفورية المعلقة أو المحفوظة مؤقتاً.',
                'صفوف sales_cancellation_requests التي sale_type فيها instant فقط.',
                'صفوف sales_returns التي تحتوي instant_sale_id، ومعها أصنافها من sales_return_items.',
                'صفوف debt_transactions التي source فيها instant_sale فقط.',
                'صفوف product_stock_movements التي reference_type فيها instant_sale فقط.',
                'صفوف sales_orders المرتبطة ببيع فوري، ومعها sales_order_items/packages/status_logs/media/deliveries/shiply_events والمرتجعات التابعة لها.',
                'صفوف maintenance المرتبطة ببيع فوري، ومعها maintenance_products و maintenance_activity_logs وسجلات maintenance_daily_box_logs التابعة لها.',
            ],
            'willNotChange' => [
                'المنتجات وكميات المخزون الحالية',
                'الصناديق وأرصدة الصناديق',
                'الطلبيات غير المرتبطة ببيع فوري',
                'الصيانة غير المرتبطة ببيع فوري',
                'العملاء والتجار',
                'فواتير الربح وأقسام النظام الأخرى',
            ],
        ], $data);
    };

    $before = $collectCounts();
    $debtImpacts = $collectDebtImpacts();

    $confirm = strtolower(trim((string) request()->query('confirm', '')));
    if (! in_array($confirm, ['yes', '1', 'true'], true)) {
        return view('purge-sales-orders', $viewData([
            'status' => 'preview',
            'before' => $before,
            'after' => null,
            'lockPath' => null,
            'debtImpacts' => $debtImpacts,
        ]));
    }

    $lockPath = storage_path('framework/instant_sales_purge_once.lock');
    if (is_file($lockPath) && request()->query('force') !== 'yes') {
        $lock = json_decode((string) file_get_contents($lockPath), true) ?: [];

        return view('purge-sales-orders', $viewData([
            'status' => 'locked',
            'before' => $lock['before'] ?? $before,
            'after' => $lock['after'] ?? null,
            'lockPath' => $lockPath,
            'executedAt' => $lock['executed_at'] ?? null,
            'debtImpacts' => $lock['debt_impacts'] ?? $debtImpacts,
        ]));
    }

    $affectedDebtPeople = [];

    \Illuminate\Support\Facades\DB::transaction(function () use ($hasTable, $hasColumn, &$affectedDebtPeople) {
        $salesOrderIds = collect();
        if ($hasColumn('sales_orders', 'instant_sale_id')) {
            $salesOrderIds = $salesOrderIds->merge(
                \Illuminate\Support\Facades\DB::table('sales_orders')
                    ->whereNotNull('instant_sale_id')
                    ->pluck('id')
            );
        }
        if ($hasColumn('instant_sales', 'sales_order_id')) {
            $salesOrderIds = $salesOrderIds->merge(
                \Illuminate\Support\Facades\DB::table('instant_sales')
                    ->whereNotNull('sales_order_id')
                    ->pluck('sales_order_id')
            );
        }
        $salesOrderIds = $salesOrderIds->filter()->unique()->values();

        $maintenanceIds = collect();
        if ($hasColumn('maintenance', 'instant_sale_id')) {
            $maintenanceIds = $maintenanceIds->merge(
                \Illuminate\Support\Facades\DB::table('maintenance')
                    ->whereNotNull('instant_sale_id')
                    ->pluck('id')
            );
        }
        if ($hasColumn('instant_sales', 'maintenance_id')) {
            $maintenanceIds = $maintenanceIds->merge(
                \Illuminate\Support\Facades\DB::table('instant_sales')
                    ->whereNotNull('maintenance_id')
                    ->pluck('maintenance_id')
            );
        }
        $maintenanceIds = $maintenanceIds->filter()->unique()->values();

        if ($hasColumn('debt_transactions', 'source')) {
            $affectedDebtPeople = \Illuminate\Support\Facades\DB::table('debt_transactions')
                ->select('customer_id', 'seller_id')
                ->where('source', 'instant_sale')
                ->groupBy('customer_id', 'seller_id')
                ->get()
                ->map(fn ($row) => [
                    'customer_id' => $row->customer_id ? (int) $row->customer_id : null,
                    'seller_id' => $row->seller_id ? (int) $row->seller_id : null,
                ])
                ->all();
        }

        if ($hasTable('sales_return_items') && $hasColumn('sales_returns', 'instant_sale_id')) {
            \Illuminate\Support\Facades\DB::table('sales_return_items')
                ->whereIn('sales_return_id', function ($query) {
                    $query->select('id')
                        ->from('sales_returns')
                        ->whereNotNull('instant_sale_id');
                })
                ->delete();
        }

        if ($hasColumn('sales_returns', 'instant_sale_id')) {
            \Illuminate\Support\Facades\DB::table('sales_returns')
                ->whereNotNull('instant_sale_id')
                ->delete();
        }

        if ($hasTable('sales_cancellation_requests')) {
            \Illuminate\Support\Facades\DB::table('sales_cancellation_requests')
                ->where('sale_type', 'instant')
                ->delete();
        }

        if ($hasColumn('debt_transactions', 'source')) {
            \Illuminate\Support\Facades\DB::table('debt_transactions')
                ->where('source', 'instant_sale')
                ->delete();
        }

        if ($hasColumn('product_stock_movements', 'reference_type')) {
            \Illuminate\Support\Facades\DB::table('product_stock_movements')
                ->where('reference_type', 'instant_sale')
                ->delete();
        }

        if ($salesOrderIds->isNotEmpty()) {
            if ($hasTable('sales_return_items') && $hasTable('sales_returns')) {
                \Illuminate\Support\Facades\DB::table('sales_return_items')
                    ->whereIn('sales_return_id', function ($query) use ($salesOrderIds) {
                        $query->select('id')
                            ->from('sales_returns')
                            ->whereIn('sales_order_id', $salesOrderIds);
                    })
                    ->delete();
            }
            if ($hasTable('sales_returns')) {
                \Illuminate\Support\Facades\DB::table('sales_returns')
                    ->whereIn('sales_order_id', $salesOrderIds)
                    ->delete();
            }
            foreach ([
                'sales_order_shiply_events',
                'sales_order_deliveries',
                'sales_order_media',
                'sales_order_status_logs',
                'sales_order_items',
                'sales_order_packages',
                'sales_orders',
            ] as $table) {
                if ($hasColumn($table, 'sales_order_id')) {
                    \Illuminate\Support\Facades\DB::table($table)->whereIn('sales_order_id', $salesOrderIds)->delete();
                } elseif ($table === 'sales_orders' && $hasTable('sales_orders')) {
                    \Illuminate\Support\Facades\DB::table('sales_orders')->whereIn('id', $salesOrderIds)->delete();
                }
            }
        }

        if ($maintenanceIds->isNotEmpty()) {
            foreach ([
                'maintenance_daily_box_logs',
                'maintenance_activity_logs',
                'maintenance_products',
                'maintenance',
            ] as $table) {
                if ($hasColumn($table, 'maintenance_id')) {
                    \Illuminate\Support\Facades\DB::table($table)->whereIn('maintenance_id', $maintenanceIds)->delete();
                } elseif ($table === 'maintenance' && $hasTable('maintenance')) {
                    \Illuminate\Support\Facades\DB::table('maintenance')->whereIn('id', $maintenanceIds)->delete();
                }
            }
        }
        if ($hasColumn('maintenance_daily_box_logs', 'instant_sale_id')) {
            \Illuminate\Support\Facades\DB::table('maintenance_daily_box_logs')
                ->whereNotNull('instant_sale_id')
                ->delete();
        }

        \Illuminate\Support\Facades\DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach (['suspended_instant_sales', 'instant_sales'] as $table) {
                if (! $hasTable($table)) {
                    continue;
                }

                \Illuminate\Support\Facades\DB::table($table)->delete();
                \Illuminate\Support\Facades\DB::statement("ALTER TABLE `{$table}` AUTO_INCREMENT = 1");
            }
        } finally {
            \Illuminate\Support\Facades\DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
    });

    foreach ($affectedDebtPeople as $person) {
        app(\App\Services\DebtLedgerService::class)->recalculateBalances(
            $person['customer_id'] ?? null,
            $person['seller_id'] ?? null
        );
    }

    $after = $collectCounts();

    file_put_contents($lockPath, json_encode([
        'executed_at' => now()->toIso8601String(),
        'before' => $before,
        'after' => $after,
        'debt_impacts' => $debtImpacts,
        'via' => 'web-route',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    return view('purge-sales-orders', $viewData([
        'status' => 'done',
        'before' => $before,
        'after' => $after,
        'lockPath' => $lockPath,
        'executedAt' => now()->toIso8601String(),
        'debtImpacts' => $debtImpacts,
    ]));
})->name('test.purge-instant-sales');

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
