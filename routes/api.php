<?php

use App\Http\Controllers\API\AssetLogs;
use App\Http\Controllers\API\CategoryController;
use App\Http\Controllers\API\CheckNotificationRulesController;
use App\Http\Controllers\API\Assets;
use App\Http\Controllers\API\AttendanceController;
use App\Http\Controllers\API\AdminNotificationCenterController;
use App\Http\Controllers\API\AppSettingsController;
use App\Http\Controllers\API\AdminAttendanceSettingsController;
use App\Http\Controllers\API\AdminEmployeeAttendanceController;
use App\Http\Controllers\API\EmployeeAttendanceOvertimeController;
use App\Http\Controllers\API\AdminAttendanceDevicesController;
use App\Http\Controllers\API\AdminFingerprintDevicesController;
use App\Http\Controllers\API\AdminFingerprintUsersController;
use App\Http\Controllers\API\FingerprintPushController;
use App\Http\Controllers\API\Authentication;
use App\Http\Controllers\API\BanksController;
use App\Http\Controllers\API\Bills;
use App\Http\Controllers\API\Boxes;
use App\Http\Controllers\API\BoxLogs;
use App\Http\Controllers\API\Customers;
use App\Http\Controllers\API\Debts;
use App\Http\Controllers\API\DebtLedger;
use App\Http\Controllers\API\Deposits;
use App\Http\Controllers\API\Destructions;
use App\Http\Controllers\API\Draws;
use App\Http\Controllers\API\EmployeeAttendanceReportController;
use App\Http\Controllers\API\EmployeeDetails;
use App\Http\Controllers\API\EmployeeOrders;
use App\Http\Controllers\API\EmployeePointCategoryController;
use App\Http\Controllers\API\EmployeePointsController;
use App\Http\Controllers\API\EmployeeRewardRuleController;
use App\Http\Controllers\API\EmployeeRemindersController;
use App\Http\Controllers\API\EmployeeSuggestionsController;
use App\Http\Controllers\API\Employees\EmployeeData;
use App\Http\Controllers\API\Employees\EmployeeOwnTasks;
use App\Http\Controllers\API\Employees\OrdersAPI;
use App\Http\Controllers\API\EmployeeTasks;
use App\Http\Controllers\API\EmployeeTaskOperationsController;
use App\Http\Controllers\API\ExpensesAPI;
use App\Http\Controllers\API\FileBoxes;
use App\Http\Controllers\API\Files;
use App\Http\Controllers\API\FollowupAPI;
use App\Http\Controllers\API\Goals;
use App\Http\Controllers\API\IncomingChecks;
use App\Http\Controllers\API\InstantSales;
use App\Http\Controllers\API\PersonProductSettingsController;
use App\Http\Controllers\API\SuspendedInstantSaleController;
use App\Http\Controllers\API\SalesOrdersController;
use App\Http\Controllers\API\ShiplyController;
use App\Http\Controllers\API\ShiplyWebhookController;
use App\Http\Controllers\API\CitiesController;
use App\Http\Controllers\API\SalesDailySessionController;
use App\Http\Controllers\API\OldInstanBuyingsAPI;
use App\Http\Controllers\API\Invoices;
use App\Http\Controllers\API\LegacyStoreImageController;
use App\Http\Controllers\API\Logs;
use App\Http\Controllers\API\MaintenanceAPI;
use App\Http\Controllers\API\Notifications;
use App\Http\Controllers\API\OfferPackageController;
use App\Http\Controllers\API\Orders;
use App\Http\Controllers\API\OutgoingChecks;
use App\Http\Controllers\API\Papers;
use App\Http\Controllers\API\Partners;
use App\Http\Controllers\API\Partnerships;
use App\Http\Controllers\API\PaymentAndRecieve;
use App\Http\Controllers\API\Pictures;
use App\Http\Controllers\API\ProductDevelopmentApi;
use App\Http\Controllers\API\Products;
use App\Http\Controllers\API\ProductAssemblyController;
use App\Http\Controllers\API\ProductTagController;
use App\Http\Controllers\API\StoreSectionController;
use App\Http\Controllers\API\Profile;
use App\Http\Controllers\API\ProfitSales;
use App\Http\Controllers\API\Projects;
use App\Http\Controllers\API\PunishmentsApi;
use App\Http\Controllers\API\RewardsApi;
use App\Http\Controllers\API\SpecialTasks;
use App\Http\Controllers\API\TaskConversionController;
use App\Models\EmployeeOrder;
use App\Http\Controllers\API\ProjectExpensesAPI;
use App\Http\Controllers\API\Reports;
use App\Http\Controllers\API\ReturnsAPI;
use App\Http\Controllers\API\ProductStockController;
use App\Http\Controllers\API\Stocks;
use App\Http\Controllers\API\Treasuries;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\WhatsAppController;
use App\Http\Controllers\API\WhatsAppTemplateController;
use App\Http\Controllers\API\WhatsAppSettingsController;
use App\Http\Controllers\API\WhatsAppWebhookController;
use App\Http\Controllers\API\MetaCatalogController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// public routes

    Route::get('/whatsapp/webhook', [WhatsAppWebhookController::class, 'verify']);
    Route::post('/whatsapp/webhook', [WhatsAppWebhookController::class, 'handle']);

    /** صور المتجر القديم (.NET) — بروكسي لـ Flutter Web (CORS) */
    Route::get('/legacy-store-image', [LegacyStoreImageController::class, 'show']);

    // Fingerprint ADMS / Push receiver (public)
    Route::match(['GET', 'POST'], '/fingerprint/push/attendance', [FingerprintPushController::class, 'attendance']);
    Route::match(['GET', 'POST'], '/iclock/cdata', [FingerprintPushController::class, 'iclockCdata']);
    Route::match(['GET', 'POST'], '/iclock/getrequest', [FingerprintPushController::class, 'iclockGetRequest']);
    Route::match(['GET', 'POST'], '/iclock/devicecmd', [FingerprintPushController::class, 'iclockDevicecmd']);
    Route::get('/iclock/test', [FingerprintPushController::class, 'iclockTest']);

    Route::post('/webhooks/shiply', [ShiplyWebhookController::class, 'handle']);

    //auth
    Route::post('/register' , [Authentication::class,'register']);
    Route::post('/send/code' , [Authentication::class,'sendCodeToEmail']);
    Route::post('/verify/code' , [Authentication::class,'verifySentToken']);


    Route::post('/login' , [Authentication::class,'login']);
    Route::post('/forgot-password', [Authentication::class, 'sendResetLinkEmail']);
    Route::post('/reset-password', [Authentication::class, 'reset']);


     Route::post('/quick/register' , [Authentication::class,'quickRegister']);





Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});


// private auth routes
Route::group(['middleware'=>['auth:sanctum','refresh.token.expiry']] , function() {

    Route::post('/logout' , [Authentication::class,'logout']);
    Route::post('/change/password' , [Authentication::class,'changePassword']);
    Route::post('/update/profile' , [Profile::class,'updatePersonalInformation']);
    Route::post('/me' , [Authentication::class,'me']);
    Route::post('/update/fcm-token' , [Authentication::class,'updateFcmToken']);

    // only for customers
    // orders
    Route::post('/add/order' , [Orders::class,'addOrder']);
    Route::get('/my/pending/orders' , [Orders::class,'pendingOrders']);
    Route::get('/my/completed/orders' , [Orders::class,'completedOrders']);
    Route::get('/my/canceled/orders' , [Orders::class,'canceledOrders']);
    Route::post('/cancel/my/order' , [Orders::class,'cancelOrder']);

    //only for customers
    // instant buyings
    Route::post('/add/instant/buying' , [OldInstanBuyingsAPI::class,'addInstantBuying']);
    Route::get('/all/instant/buyings' , [OldInstanBuyingsAPI::class,'allInstantBuyings']);







});

Route::group(['middleware'=>['auth:sanctum','check.permission:Special Tasks','refresh.token.expiry']] , function() {

      // special tasks
    Route::post('/create/special/task' , [SpecialTasks::class,'createSpecialTask']);
    Route::get('/completed/special/tasks' , [SpecialTasks::class,'completedSpecialTasks']);
    Route::get('/ongoing/special/tasks' , [SpecialTasks::class,'ongoingSpecialTasks']);
    Route::get('/canceled/special/tasks' , [SpecialTasks::class,'canceledSpecialTasks']);
    Route::post('/cancel/special/task' , [SpecialTasks::class,'cancelSpecialTask']);
    Route::post('/restore/special/task' , [SpecialTasks::class,'restoreSpecialTask']);
    Route::post('/show/special/task' , [SpecialTasks::class,'showSpecialTaskDetails']);
    Route::post('/cancel/special/task/with/repitition' , [SpecialTasks::class,'cancelSpecialTaskWithRepition']);
    Route::get('/no-date/special/tasks' , [SpecialTasks::class,'noDateTasks']);
    Route::post('/change/special/task/to/completed' , [SpecialTasks::class,'changeSpecialTaskToCompleted']);
        Route::post('/change/sub/special/task/to/completed' ,
     [SpecialTasks::class,'changeSubTaskToCompleted'])
    ;
    Route::post('/transfer/special/task' , [SpecialTasks::class,'transerTask']);
    Route::post('/update/special/task' , [SpecialTasks::class,'updateTask']);
    Route::post('/convert/special/task/to/employee' , [TaskConversionController::class,'specialToEmployee']);

});

Route::group(['middleware'=>['auth:sanctum','check.permission:Employees Section','refresh.token.expiry']] , function() {
    // employees
    Route::post('/create/employee' , [EmployeeDetails::class,'addEmployee']);
    Route::get('/working/times' , [EmployeeDetails::class,'workingTimes']);
    Route::get('/financial/dues' , [EmployeeDetails::class,'financialDues']);
    Route::post('/edit/employee' , [EmployeeDetails::class,'editEmployee']);
    Route::post('/delete/employee' , [EmployeeDetails::class,'deleteEmployee']);
    Route::get('/all/permissions' , [EmployeeDetails::class,'allPermissions']);
    Route::post('/employee/permissions' , [EmployeeDetails::class,'getEmployeePermissions']);

    Route::post('/add/points/to/employee' , [EmployeeDetails::class,'addPoints']);
    Route::post('/minus/points/from/employee' , [EmployeeDetails::class,'minusPoints']);

    Route::post('/show/employee/financial/details' , [EmployeeDetails::class,'showFinancialDetails']);
    Route::post('/pay/employee/salary' , [EmployeeDetails::class,'paySalary']);
    Route::post('/get/employee/financial/data/report' , [EmployeeDetails::class,'employeeReportData']);

    Route::get('/employee/logs' , [Logs::class,'getEmployeesLogs']);
    Route::get('/employee/attendance/history', [EmployeeDetails::class, 'employeeAttendanceHistory']);
    Route::get('/employee/attendance/weekly-off-import-candidates', [EmployeeDetails::class, 'weeklyOffAttendanceImportCandidates']);
    Route::post('/employee/attendance/import-weekly-off-day', [EmployeeDetails::class, 'importWeeklyOffAttendanceDay']);
    Route::post('/admin/employees/{employeeId}/attendance/manual-checkout', [AdminEmployeeAttendanceController::class, 'manualCheckout'])
        ->whereNumber('employeeId');
    Route::put('/admin/employees/{employeeId}/attendance/day', [AdminEmployeeAttendanceController::class, 'updateDay'])
        ->whereNumber('employeeId');
    Route::get('/employee/attendance/overtime-requests', [EmployeeAttendanceOvertimeController::class, 'index']);
    Route::post('/employee/attendance/overtime-requests/{requestId}/approve', [EmployeeAttendanceOvertimeController::class, 'approve'])
        ->whereNumber('requestId');
    Route::post('/employee/attendance/overtime-requests/{requestId}/reject', [EmployeeAttendanceOvertimeController::class, 'reject'])
        ->whereNumber('requestId');
    Route::get('/employee-attendance/reports', [EmployeeAttendanceReportController::class, 'index']);

    // Employee points and rewards (manual management)
    Route::get('/employees/points/categories', [EmployeePointsController::class, 'categories']);
    Route::post('/employees/{employee}/points/add', [EmployeePointsController::class, 'add'])
        ->whereNumber('employee');
    Route::post('/employees/{employee}/points/deduct', [EmployeePointsController::class, 'deduct'])
        ->whereNumber('employee');
    Route::get('/employees/{employee}/points/logs', [EmployeePointsController::class, 'logs'])
        ->whereNumber('employee');
    Route::get('/employees/{employee}/points/monthly-summary', [EmployeePointsController::class, 'monthlySummary'])
        ->whereNumber('employee');

    // Global points (admin only manages all employees in one place)
    Route::get('/employee-points/employees', [EmployeePointsController::class, 'globalEmployees']);
    Route::get('/employee-points/reports', [EmployeePointsController::class, 'globalReport']);

    // Reward rules CRUD
    Route::get('/employee-reward-rules', [EmployeeRewardRuleController::class, 'index']);
    Route::post('/employee-reward-rules', [EmployeeRewardRuleController::class, 'store']);
    Route::put('/employee-reward-rules/{id}', [EmployeeRewardRuleController::class, 'update'])
        ->whereNumber('id');
    Route::delete('/employee-reward-rules/{id}', [EmployeeRewardRuleController::class, 'destroy'])
        ->whereNumber('id');

    // Point categories CRUD (admin defines the value of each behavior)
    Route::get('/employee-point-categories', [EmployeePointCategoryController::class, 'index']);
    Route::post('/employee-point-categories', [EmployeePointCategoryController::class, 'store']);
    Route::put('/employee-point-categories/{id}', [EmployeePointCategoryController::class, 'update'])
        ->whereNumber('id');
    Route::delete('/employee-point-categories/{id}', [EmployeePointCategoryController::class, 'destroy'])
        ->whereNumber('id');

    // Banks (checks)
    Route::get('/banks', [BanksController::class, 'index']);
    Route::post('/banks', [BanksController::class, 'store']);
    Route::post('/banks/find-or-create', [BanksController::class, 'findOrCreate']);
    Route::put('/banks/{id}', [BanksController::class, 'update'])->whereNumber('id');
    Route::delete('/banks/{id}', [BanksController::class, 'destroy'])->whereNumber('id');


       // employee orders
      Route::get('/employee/loan/orders' , [EmployeeOrders::class,'employeeLoanOrders']);
      Route::get('/employee/overtime/orders' , [EmployeeOrders::class,'employeeOvertimeOrders']);
      Route::get('/employees/{employee}/advances' , [EmployeeOrders::class,'employeeAdvancesByMonth'])
          ->whereNumber('employee');

      Route::post('/approve/employee/loan/order' , [EmployeeOrders::class,'approveLoanRequest']);
      Route::post('/reject/employee/order' , [EmployeeOrders::class,'reject']);
   
      Route::post('/show/employee/loan/order' , [EmployeeOrders::class,'showLoanOrder']);
      Route::post('/show/employee/overtime/order' , [EmployeeOrders::class,'showOvertimeOrder']);
      Route::post('/approve/employee/overtime/order' , [EmployeeOrders::class,'approveOvertimeRequest']);

    // qr generation
    Route::get('/qr-generation', [AttendanceController::class, 'generateQr']);
    Route::get('/qr-history', [AttendanceController::class, 'qrHistory']);


});

Route::group(['middleware'=>['auth:sanctum','check.permission:Employee Impersonation','refresh.token.expiry']] , function() {
    Route::post('/employee/impersonate/{employeeId}', [\App\Http\Controllers\API\EmployeeImpersonationController::class, 'impersonate'])
        ->whereNumber('employeeId');
});

Route::group(['middleware'=>['auth:sanctum','check.permission:Employee Tasks','refresh.token.expiry']] , function() {

      // employee tasks
 
    Route::post('/create/employee/task' , [EmployeeTasks::class,'createEmployeeTask']);
    // تعديل مهمة موظف يتطلب (بالإضافة لصلاحية "مهام الموظفين") صلاحية "تعديل مهمة موظف" — والأدمن يتجاوزهما.
    Route::post('/edit/employee/task' , [EmployeeTasks::class,'updateEmployeeTask'])
        ->middleware('check.permission:Edit Employee Task');
 
    Route::get('/employee/completed/tasks' , [EmployeeTasks::class,'completedTasks']);
    Route::get('/employee/ongoing/tasks' , [EmployeeTasks::class,'ongoingTasks']);
    Route::get('/employee/canceled/tasks' , [EmployeeTasks::class,'canceledTasks']);
    Route::post('/cancel/employee/task' , [EmployeeTasks::class,'cancelEmployeeTask']);
    Route::post('/restore/employee/task' , [EmployeeTasks::class,'restoreEmployeeTask']);
    Route::post('/cancel/employee/task/with/repetition' , [EmployeeTasks::class,'cancelEmployeeTaskWithRepetition']);

    Route::post('/create/employee/task/v2', [EmployeeTaskOperationsController::class, 'createWithTemplate']);
    Route::post('/employee/task/start', [EmployeeTaskOperationsController::class, 'startTask']);
    Route::post('/employee/task/submit', [EmployeeTaskOperationsController::class, 'submitTask']);
    Route::post('/employee/task/approve', [EmployeeTaskOperationsController::class, 'approveTask']);
    Route::post('/employee/task/reject', [EmployeeTaskOperationsController::class, 'rejectTask']);
    Route::post('/employee/task/reopen', [EmployeeTaskOperationsController::class, 'reopenTask']);
    Route::post('/employee/task/timeline', [EmployeeTaskOperationsController::class, 'getTimeline']);
    Route::get('/employee/task/performance', [EmployeeTaskOperationsController::class, 'getPerformance']);
    Route::post('/convert/employee/task/to/special', [TaskConversionController::class, 'employeeToSpecial']);

});

Route::group(['middleware'=>['auth:sanctum','check.permission:Employee Tasks','refresh.token.expiry']] , function() {
    Route::get('/employee-reminders', [EmployeeRemindersController::class, 'index']);
    Route::post('/employee-reminders', [EmployeeRemindersController::class, 'store']);
    Route::put('/employee-reminders/{reminder}', [EmployeeRemindersController::class, 'update'])
        ->whereNumber('reminder');
    Route::get('/employee-reminders/{reminder}/history', [EmployeeRemindersController::class, 'history'])
        ->whereNumber('reminder');
    Route::delete('/employee-reminders/{reminder}', [EmployeeRemindersController::class, 'destroy'])
        ->whereNumber('reminder');
});

Route::group(['middleware'=>['auth:sanctum','check.permission:Projects and Purchases Management','refresh.token.expiry']] , function() {

      // projects
    Route::post('/create/project' , [Projects::class,'createProject']);
    Route::post('/show/project' , [Projects::class,'showProjectDetails']);
    Route::get('/ongoing/project' , [Projects::class,'ongoingProjects']);
    Route::get('/completed/project' , [Projects::class,'completedProjects']);
    Route::post('/edit/project' , [Projects::class,'editProject']);
    Route::post('/complete/a/project' , [Projects::class,'completeProject']);
    Route::post('/project/sales' , [Projects::class,'projectSales']);
    Route::post('/add/product/to/project' , [Projects::class,'addProductToProject']);

   //partners
   Route::get('/all/partners' , [Partners::class,'allPartners']);
        //project expenses
    Route::post('/add/project/expense' , [ProjectExpensesAPI::class,'addExpenses']);
    Route::post('/get/project/expenses' , [ProjectExpensesAPI::class,'projectExpenses']);


});

Route::group(['middleware'=>['auth:sanctum','check.permission:General Data,Data Completion,Sales','refresh.token.expiry']] , function() {

      //customers
    Route::post('/create/person' , [Customers::class,'store']);
    Route::post('/show/person' , [Customers::class,'showCustomer']);
    Route::post('/cancel/customer' , [Customers::class,'deleteCustomer']);
    Route::post('/restore/customer' , [Customers::class,'restoreCustomer']);
    Route::post('/edit/person' , [Customers::class,'editPerson']);
    Route::post('/delete/person' , [Customers::class,'deletePerson']);


    Route::get('/main/page/customers' , [Customers::class,'getCustomersForMainPage']);
    Route::get('/main/page/sellers' , [Customers::class,'getSellersForMainPage']);
    Route::get('/main/page/incomplete/persons' , [Customers::class,'getIncompletePersons']);

});

Route::group(['middleware'=>['auth:sanctum','check.permission:Sales','refresh.token.expiry']] , function() {


       // deposits
   Route::post('/add/deposit' , [Deposits::class,'store']);

       // instant sales
      Route::get('/all/instant/sales' , [InstantSales::class,'getInstantSales']);
      Route::get('/show/instant/sale' , [InstantSales::class,'showInstantSale']);
      Route::post('/create/instant/sale' , [InstantSales::class,'store']);
      Route::get('/offer/packages/for-sale' , [OfferPackageController::class,'forSale']);
      Route::post('/edit/instant/sale' , [InstantSales::class,'edit']);
      Route::post('/cancel/instant/sale' , [InstantSales::class,'cancel']);

      Route::post('/get/product/projects' , [InstantSales::class,'getProjectsOfProduct']);
      Route::post('/get/subsales' , [InstantSales::class,'getSubSales']);
      Route::post('/get/instant/sale/invoice' , [InstantSales::class,'invoiceDetails']);
      Route::get('/instant/sale/customer-product-prices', [InstantSales::class, 'customerProductPriceHistory']);

      // suspended (pending) instant sales — الفواتير العالقة
      Route::get('/suspended/instant/sales', [SuspendedInstantSaleController::class, 'index']);
      Route::get('/suspended/instant/sales/count', [SuspendedInstantSaleController::class, 'count']);
      Route::get('/suspended/instant/sale', [SuspendedInstantSaleController::class, 'show']);
      Route::post('/suspended/instant/sale', [SuspendedInstantSaleController::class, 'store']);
      Route::post('/suspended/instant/sale/note', [SuspendedInstantSaleController::class, 'addNote']);
      Route::post('/suspended/instant/sale/complete', [SuspendedInstantSaleController::class, 'complete']);
      Route::post('/suspended/instant/sale/cancel', [SuspendedInstantSaleController::class, 'cancel']);

      // sales orders — الطلبيات
      Route::get('/sales/orders', [SalesOrdersController::class, 'index']);
      Route::get('/sales/order', [SalesOrdersController::class, 'show']);
      Route::post('/sales/order', [SalesOrdersController::class, 'store']);
      Route::post('/sales/order/check-stock', [SalesOrdersController::class, 'checkStock']);
      Route::post('/sales/order/stock-availability', [SalesOrdersController::class, 'stockAvailability']);
      Route::post('/sales/order/update', [SalesOrdersController::class, 'update']);
      Route::post('/sales/order/confirm', [SalesOrdersController::class, 'confirm']);
      Route::post('/sales/order/ready', [SalesOrdersController::class, 'markReady']);
      Route::post('/sales/order/cancel', [SalesOrdersController::class, 'cancel']);
      Route::post('/sales/order/revert', [SalesOrdersController::class, 'revertStatus']);
      Route::post('/sales/order/postpone', [SalesOrdersController::class, 'postpone']);
      Route::post('/sales/order/handover', [SalesOrdersController::class, 'handover']);
      Route::post('/sales/order/deliver', [SalesOrdersController::class, 'deliver']);
      Route::post('/sales/order/settle', [SalesOrdersController::class, 'settle']);
      Route::post('/sales/order/archive', [SalesOrdersController::class, 'archive']);
      Route::post('/sales/order/media', [SalesOrdersController::class, 'uploadMedia']);
      Route::post('/sales/order/partial-deliver', [SalesOrdersController::class, 'partialDeliver']);
      Route::post('/sales/order/follow-up', [SalesOrdersController::class, 'followUp']);
      Route::post('/sales/order/partial-return', [SalesOrdersController::class, 'partialReturn']);
      Route::post('/sales/order/alternative-return', [SalesOrdersController::class, 'alternativeReturn']);
      Route::post('/sales/order/mark-stuck', [SalesOrdersController::class, 'markStuck']);
      Route::post('/sales/orders/bulk-status', [SalesOrdersController::class, 'bulkStatus']);
      Route::get('/sales/order/statement', [SalesOrdersController::class, 'statement']);
      Route::get('/cities', [CitiesController::class, 'index']);
      Route::get('/delivery/companies', [CitiesController::class, 'deliveryCompanies']);
      Route::get('/shiply/address-options', [ShiplyController::class, 'addressOptions']);
      Route::post('/shiply/calculate-delivery-fee', [ShiplyController::class, 'calculateDeliveryFee']);
      Route::get('/shiply/print-parcel', [ShiplyController::class, 'printParcel']);

      //Route::post('/attach/project/of/product/to/sale' , [InstantSales::class,'attachProjectToProductInSale']);

    // profit sales
      Route::get('/all/profit/sales' , [ProfitSales::class,'getProfitSales']);
      Route::get('/show/profit/sale' , [ProfitSales::class,'showProfitSale']);
      Route::post('/create/profit/sale' , [ProfitSales::class,'store']);
      Route::post('/cancel/profit/sale' , [ProfitSales::class,'cancel']);
      Route::post('/edit/profit/sale' , [ProfitSales::class,'edit']);

      // sales daily session / cash drawer
      Route::get('/sales/daily-session/current', [SalesDailySessionController::class, 'current']);
      Route::post('/sales/daily-session/open', [SalesDailySessionController::class, 'open']);
      Route::get('/sales/daily-sessions/open', [SalesDailySessionController::class, 'openSessions']);
      Route::get('/sales/daily-sessions/today-overview', [SalesDailySessionController::class, 'todayOverview']);
      Route::get('/sales/daily-sessions', [SalesDailySessionController::class, 'index']);
      Route::get('/sales/daily-sessions/{sessionId}', [SalesDailySessionController::class, 'show']);
      Route::get('/sales/daily-sessions/{sessionId}/close-payload', [SalesDailySessionController::class, 'closePayload']);
      Route::post('/sales/daily-closing/request', [SalesDailySessionController::class, 'requestClosing']);
      Route::get('/sales/daily-closing/pending', [SalesDailySessionController::class, 'pendingClosing']);
      Route::post('/sales/daily-closing/direct', [SalesDailySessionController::class, 'directClose']);
      Route::post('/sales/daily-closing/approve', [SalesDailySessionController::class, 'approveClosing']);
      Route::post('/sales/daily-closing/reject', [SalesDailySessionController::class, 'rejectClosing']);
      Route::post('/sales/daily-reopen/request', [SalesDailySessionController::class, 'requestReopen']);
      Route::get('/sales/daily-reopen/pending', [SalesDailySessionController::class, 'pendingReopen']);
      Route::post('/sales/daily-reopen/approve', [SalesDailySessionController::class, 'approveReopen']);
      Route::post('/sales/daily-reopen/reject', [SalesDailySessionController::class, 'rejectReopen']);
      Route::post('/sales/cancellation/request', [SalesDailySessionController::class, 'requestCancellation']);
      Route::get('/sales/cancellation/pending', [SalesDailySessionController::class, 'pendingCancellations']);
      Route::post('/sales/cancellation/approve', [SalesDailySessionController::class, 'approveCancellation']);
      Route::post('/sales/cancellation/reject', [SalesDailySessionController::class, 'rejectCancellation']);

});


Route::group(['middleware'=>['auth:sanctum','check.permission:Follow-up Section','refresh.token.expiry']] , function() {
      // followups
    Route::post('/add/followup' , [FollowupAPI::class,'storeFollowup']);
    Route::get('/get/initial/followups' , [FollowupAPI::class,'getInitialFollowups']);
    Route::get('/get/inform/person/followups' , [FollowupAPI::class,'getSecondStepFollowups']);
    Route::get('/get/finish/and/agreement/followups' , [FollowupAPI::class,'getThirdStepFollowups']);
    Route::get('/get/archived/followups' , [FollowupAPI::class,'getArchivedFollowups']);

    Route::get('/canceled/followup' , [FollowupAPI::class,'getCanceledFollowups']);
   
    Route::post('/cancel/followup' , [FollowupAPI::class,'cancelFollowUp']);
    Route::post('/delete/followup' , [FollowupAPI::class,'deleteFollowup']);

    Route::post('/update/followup' , [FollowupAPI::class,'updateFollowup']);
    Route::post('/show/followup' , [FollowupAPI::class,'showFollowup']);

    
    Route::post('/update/followup/step/three' , [FollowupAPI::class,'updateFollowupStep3']);
    Route::post('/followup/store/customer' , [FollowupAPI::class,'storeCustomer']);

});

Route::group(['middleware'=>['auth:sanctum','check.permission:Maintenance','refresh.token.expiry']] , function() {

      //maintenance
    Route::post('/add/maintenance' , [MaintenanceAPI::class,'store']);
    Route::get('/get/new/maintenances' , [MaintenanceAPI::class,'getNewMaintenances']);
    Route::get('/get/ongoing/maintenances' , [MaintenanceAPI::class,'getPendingMaintenances']);
    Route::get('/get/ready/maintenances' , [MaintenanceAPI::class,'getReadyMaintenances']);
    Route::get('/get/delivered/maintenances' , [MaintenanceAPI::class,'getDoneMaintenances']);
    Route::post('/change/maintenance/to/ongoing' , [MaintenanceAPI::class,'changeToPending']);
    Route::post('/change/maintenance/to/ready' , [MaintenanceAPI::class,'changeToReady']);
    Route::post('/change/maintenance/to/delivered' , [MaintenanceAPI::class,'changeToDone']);
    Route::post('/show/maintenance' , [MaintenanceAPI::class,'showMaintenance']);
    Route::post('/change/maintenance/status' , [MaintenanceAPI::class,'commonUpdate']);
    Route::post('/maintenance/sync/products' , [MaintenanceAPI::class,'syncProducts']);
    Route::post('/maintenance/deliver' , [MaintenanceAPI::class,'deliver']);
    Route::post('/maintenance/activity-log' , [MaintenanceAPI::class,'activityLog']);
    Route::post('/maintenance/invoice' , [MaintenanceAPI::class,'invoiceData']);
    Route::post('/maintenance/invoice/pdf' , [MaintenanceAPI::class,'invoicePdf']);
    Route::post('/maintenance/daily-box' , [MaintenanceAPI::class,'dailyBox']);

  

});

Route::group(['middleware'=>['auth:sanctum','check.permission:Boxes Section','refresh.token.expiry']] , function() {

     // boxes
   Route::post('/add/box' , [Boxes::class,'addBox']);
   Route::post('/edit/box' , [Boxes::class,'editBox']);
   Route::post('/show/box' , [Boxes::class,'showBox']);
   Route::post('/add/box/balance' , [Boxes::class,'addBalance']);
   Route::post('/transfer/box/balance' , [Boxes::class,'transferBalance']);
   Route::post('/delete/box' , [Boxes::class,'deleteBox']);

  Route::post('/box/logs/report' , [BoxLogs::class,'boxLogsReport']);

});

Route::group(['middleware'=>['auth:sanctum','check.permission:Boxes Section,Daily Boxes','refresh.token.expiry']] , function() {

   Route::get('/get/hidden/boxes' , [Boxes::class,'getHiddentBoxes']);

  //box logs
  Route::get('/all/box/logs' , [BoxLogs::class,'allBoxLogs']);

});

Route::group(['middleware'=>['auth:sanctum','check.permission:Debts','refresh.token.expiry']] , function() {

     // debts
   Route::post('/add/debt' , [Debts::class,'store']);
   Route::post('/show/debt' , [Debts::class,'showDebt']);
   Route::post('/edit/debt' , [Debts::class,'editDebt']);
   Route::get('/total/debts/owed/to/us' , [Debts::class,'getDebtsOwedToUsTotal']);
   Route::get('/total/debts/we/owe' , [Debts::class,'getDebtsWeOweTotal']);
   Route::get('/get/debts/we/owe' , [Debts::class,'getDebtsWeOwe']);
   Route::get('/get/debts/owed/to/us' , [Debts::class,'getDebtsOwedToUs']);
   Route::post('/person/debts' , [Debts::class,'customerDebts']);
   Route::post('/get/debts/reports' , [Debts::class,'debtReports']);

   // debt ledger (Konnash-style account book)
   Route::get('/debt-ledger/summary', [DebtLedger::class, 'summary']);
   Route::get('/debt-ledger/people', [DebtLedger::class, 'people']);
   Route::get('/debt-ledger/people-picker', [DebtLedger::class, 'peoplePicker']);
   Route::get('/debt-ledger/categories', [DebtLedger::class, 'categories']);
   Route::post('/debt-ledger/categories', [DebtLedger::class, 'storeCategory']);
   Route::post('/debt-ledger/categories/{id}/update', [DebtLedger::class, 'updateCategory']);
   Route::post('/debt-ledger/categories/{id}/delete', [DebtLedger::class, 'deleteCategory']);
   Route::get('/debt-ledger/person', [DebtLedger::class, 'person']);
   Route::post('/debt-ledger/person/meta', [DebtLedger::class, 'updatePersonMeta']);
   Route::post('/debt-ledger/person/share-link', [DebtLedger::class, 'createPersonShareLink']);
   Route::get('/debt-ledger/person/archive', [DebtLedger::class, 'personArchive']);
   Route::get('/debt-ledger/person/deleted', [DebtLedger::class, 'personDeleted']);
   Route::post('/debt-ledger/transactions/archive', [DebtLedger::class, 'archiveTransactionsBulk']);
   Route::post('/debt-ledger/transactions/restore', [DebtLedger::class, 'restoreTransactionsBulk']);
   Route::post('/debt-ledger/transaction', [DebtLedger::class, 'storeTransaction']);
   Route::get('/debt-ledger/transaction/{id}', [DebtLedger::class, 'showTransaction']);
   Route::get('/debt-ledger/transaction/{id}/activity', [DebtLedger::class, 'transactionActivity']);
   Route::get('/debt-ledger/person/activity', [DebtLedger::class, 'personActivity']);
   Route::post('/debt-ledger/transaction/{id}/update', [DebtLedger::class, 'updateTransaction']);
   Route::post('/debt-ledger/transaction/{id}/archive', [DebtLedger::class, 'archiveTransaction']);
   Route::post('/debt-ledger/transaction/{id}/delete', [DebtLedger::class, 'deleteTransaction']);
   Route::post('/debt-ledger/person/report', [DebtLedger::class, 'personReport']);

});

Route::group(['middleware'=>['auth:sanctum','check.permission:Checks','refresh.token.expiry']] , function() {
      //outgoing checks
      Route::post('/add/outgoing/check' , [OutgoingChecks::class,'store']);
      Route::post('/cancel/an/outgoing/check' , [OutgoingChecks::class,'cancelCheck']);
      Route::post('/return/an/outgoing/check' , [OutgoingChecks::class,'returnCheck']);
      Route::post('/cash/an/outgoing/check' , [OutgoingChecks::class,'cashCheck']);

      Route::post('/cash/an/outgoing/check/to/person' , [OutgoingChecks::class,'cashCheckToPerson']);

      Route::get('/not-cashed/outgoing/checks' , [OutgoingChecks::class,'notCashedChecks']);
      Route::get('/cashed/to/person/outgoing/checks' , [OutgoingChecks::class,'cashedToPersonChecks']);
      Route::get('/cancelled/outgoing/checks' , [OutgoingChecks::class,'cancelledChecks']);
      Route::get('/returned/outgoing/checks' , [OutgoingChecks::class,'returnedChecks']);
      Route::get('/general/outgoing/checks/data' , [OutgoingChecks::class,'generalOutgoingChecksData']);
      Route::get('/general/checks/data/first/page' , [OutgoingChecks::class,'generalDataFirstPage']);
      Route::get('/cashed/outgoing/checks' , [OutgoingChecks::class,'cashedChecks']);
      Route::get('/archived/outgoing/checks' , [OutgoingChecks::class,'archive']);
      Route::post('/edit/outgoing/check' , [OutgoingChecks::class,'editCheck']);
      Route::post('/delete/outgoing/check' , [OutgoingChecks::class,'deleteCheck']);
      Route::post('/cash/outgoing/check/from/box' , [OutgoingChecks::class,'cashFromBox']);


    //incoming checks
      Route::post('/add/incoming/check' , [IncomingChecks::class,'store']);
      Route::post('/add/incoming/checks/batch' , [IncomingChecks::class,'storeBatch']);
      Route::get('/check-notification-rules' , [CheckNotificationRulesController::class,'index']);
      Route::post('/check-notification-rules' , [CheckNotificationRulesController::class,'store']);
      Route::get('/check-notification-rules/check-owner' , [CheckNotificationRulesController::class,'checkOwner']);
      Route::put('/check-notification-rules/check-owner-phone' , [CheckNotificationRulesController::class,'updateCheckOwnerPhone']);
      Route::put('/check-notification-rules/{rule}' , [CheckNotificationRulesController::class,'update']);
      Route::delete('/check-notification-rules/{rule}' , [CheckNotificationRulesController::class,'destroy']);
      Route::post('/cash/incoming/check/to/person' , [IncomingChecks::class,'cashCheckToPerson']);
      Route::post('/cash/incoming/check/to/box' , [IncomingChecks::class,'cashCheckToBox']);

      Route::post('/cancel/an/incoming/check' , [IncomingChecks::class,'cancelCheck']);
      Route::post('/return/an/incoming/check' , [IncomingChecks::class,'returnCheck']);
      Route::post('/cash/an/incoming/check' , [IncomingChecks::class,'cashCheck']);
      Route::post('/show/check' , [IncomingChecks::class,'showCheck']);
      Route::post('/edit/incoming/check' , [IncomingChecks::class,'editCheck']);
      Route::post('/delete/incoming/check' , [IncomingChecks::class,'deleteCheck']);


      Route::get('/not-cashed/incoming/checks' , [IncomingChecks::class,'notCashedChecks']);
      Route::get('/cashed/to/person/incoming/checks' , [IncomingChecks::class,'cashedToPersonChecks']);
      Route::get('/cancelled/incoming/checks' , [IncomingChecks::class,'cancelledChecks']);
      Route::get('/returned/incoming/checks' , [IncomingChecks::class,'returnedChecks']);
      Route::get('/cashed/incoming/checks' , [IncomingChecks::class,'cashedChecks']);
      Route::get('/general/incoming/checks/data' , [IncomingChecks::class,'generalIncomingChecksData']);
      Route::get('/cashed/to/box/incoming/checks' , [IncomingChecks::class,'cashedToBoxChecks']);

      Route::get('/archived/incoming/checks' , [IncomingChecks::class,'archive']);

});

Route::group(['middleware'=>['auth:sanctum','check.permission:Expenses and Financial Affairs','refresh.token.expiry']] , function() {

      // assets
    Route::post('/add/asset' , [Assets::class,'store']);
    Route::get('/get/all/assets' , [Assets::class,'getAssets']);
    Route::get('/depreciate/all/assets' , [Assets::class,'depreciatAllAssets']);
    Route::post('/show/asset' , [Assets::class,'showAsset']);
    Route::post('/edit/asset' , [Assets::class,'editAsset']);
    Route::post('/delete/asset' , [Assets::class,'deleteAsset']);
    Route::post('/depreciate/one/asset' , [Assets::class,'depreciateOneAsset']);

  // asset logs
    Route::get('/get/all/asset/logs' , [AssetLogs::class,'getAllLogs']);
    Route::post('/get/asset/logs' , [AssetLogs::class,'getAssetLogs']);
    Route::get('/get/all/asset/logs/report' , [AssetLogs::class,'getAllLogsReport']);


    // expenses
      Route::post('/store/expense' , [ExpensesAPI::class,'store']);
      Route::get('/get/all/expenses' , [ExpensesAPI::class,'getExpenses']);
      Route::post('/show/expense' , [ExpensesAPI::class,'showExpense']);
      Route::post('/edit/expense' , [ExpensesAPI::class,'editExpense']);

    // destructions
      Route::post('/store/destruction' , [Destructions::class,'store']);
      Route::get('/get/all/destructions' , [Destructions::class,'getDestructions']);
      Route::post('/show/destruction' , [Destructions::class,'showDestruction']);

   
   
   
    // treasuries
    Route::post('/store/treasury', [Treasuries::class, 'store']);
    Route::get('/get/all/treasuries', [Treasuries::class, 'getTreasuries']);
    Route::post('/cancel/treasury', [Treasuries::class, 'cancelTreasury']);

        // fileBoxes
    Route::post('/store/file-box', [FileBoxes::class, 'store']);
    Route::get('/all/file-boxes', [FileBoxes::class, 'allFileBoxes']);
    Route::post('/file-box/details', [FileBoxes::class, 'fileBoxDetails']);
    Route::post('/cancel/file-box', [FileBoxes::class, 'cancelFileBox']);

        // files
    Route::post('/store/file', [Files::class, 'store']);
    Route::post('/delete/file', [Files::class, 'cancelFile']);
    Route::get('/get/all/files', [Files::class, 'allFiles']);
    Route::post('/file/papers', [Files::class, 'getFileDetails']);

    // pictures
    Route::post('/store/picture', [Pictures::class, 'store']);
    Route::get('/get/all/pictures', [Pictures::class, 'getAllPictures']);
    Route::post('/show/picture', [Pictures::class, 'showPicture']);
    Route::post('/edit/picture', [Pictures::class, 'editPicture']);
    Route::post('/delete/picture', [Pictures::class, 'deletePicture']);

    // papers
    Route::post('/store/paper', [Papers::class, 'store']);
    Route::get('/get/all/papers', [Papers::class, 'getPapers']);
    Route::post('/cancel/paper', [Papers::class, 'cancelPaper']);
    Route::post('/get/paper/details', [Papers::class, 'showPaper']);
    Route::post('/edit/paper', [Papers::class, 'editPaper']);


});



Route::group(['middleware'=>['auth:sanctum','check.permission:Goal Creation','refresh.token.expiry']] , function() {

      // goals
    Route::post('/add/goal' , [Goals::class,'createGoal']);
    Route::post('/show/goal' , [Goals::class,'showGoal']);
    // Route::get('/public/goals' , [Goals::class,'publicGoals']);
    // Route::get('/private/goals' , [Goals::class,'privateGoals']);
    // Route::get('/completed/goals' , [Goals::class,'completedGoals']);
//    Route::get('/canceled/goals' , [Goals::class,'canceledGoals']);

    Route::get('/get/all/goals' , [Goals::class,'getGoals']);
 
    Route::post('/cancel/goal' , [Goals::class,'cancelGoal']);
    Route::post('/transfer/goal' , [Goals::class,'transferGoal']);
    Route::post('/edit/goal' , [Goals::class,'editGoal']);
    Route::post('/delete/goal' , [Goals::class,'deleteGoal']);

    //Route::post('/restore/goal' , [Goals::class,'restoreGoal']);
});


// MUTUAL ROUTES
Route::group(['middleware' => ['auth:sanctum','check.permission:Sales,Follow-up Section,Projects and Purchases Management,Purchasing Section','refresh.token.expiry']], function () {
      //products
    Route::get('/all/products' , [Products::class,'allproducts']);
    Route::post('/product/retail-price' , [Products::class,'updateRetailPrice']);
    Route::post('/products/paste-suggestions' , [Products::class,'pasteSuggestions']);
    Route::post('/products/paste-alias' , [Products::class,'storePasteAlias']);
    Route::post('/products/ocr-text' , [Products::class,'ocrText']);
  });

Route::group(['middleware' => ['auth:sanctum','check.permission:General Data,Sales','refresh.token.expiry']], function () {
    Route::get('/person-product-settings', [PersonProductSettingsController::class, 'index']);
    Route::post('/person-product-settings', [PersonProductSettingsController::class, 'store']);
    Route::post('/person-product-settings/delete', [PersonProductSettingsController::class, 'destroy']);
});

Route::group(['middleware' => ['auth:sanctum','check.permission:Boxes Section,Daily Boxes,Checks,Sales,Goal Creation','refresh.token.expiry']], function () {

   Route::get('/get/shown/boxes' , [Boxes::class,'getShownBoxes']);

  });

Route::group(['middleware' => ['auth:sanctum','check.permission:General Data,Debts,Checks,Maintenance,Follow-up Section,Goal Creation,Projects and Purchases Management,Sales','refresh.token.expiry']], function () {

    //customers
    Route::get('/all/customers' , [Customers::class,'allCustomers']);
  });

Route::group(['middleware' => ['auth:sanctum','check.permission:General Data,Checks,Debts,Maintenance,Follow-up Section,Goal Creation,Projects and Purchases Management,Purchasing Section,Sales','refresh.token.expiry']], function () {

    //sellers
    Route::get('/all/sellers' , [Customers::class,'allSellers']);
  });

Route::group(['middleware' => ['auth:sanctum','check.permission:Sales','refresh.token.expiry']], function () {

   // deposits
   Route::post('/add/deposit' , [Deposits::class,'store']);
  });

Route::group(['middleware' => ['auth:sanctum','check.permission:Employees Section,Employee Tasks,Goal Creation','refresh.token.expiry']], function () {
    Route::get('/employees' , [EmployeeDetails::class,'employeesList']);

  });

Route::group(['middleware' => ['auth:sanctum','check.permission:Stock,Sales','refresh.token.expiry']], function () {
    Route::get('/get/all/projects' , [Stocks::class,'allProjects']);

  });

// Route::group(['middleware' => ['auth:sanctum','check.permission:General Data,Data Completion','refresh.token.expiry']], function () {
//     Route::post('/edit/person' , [Customers::class,'editPerson']);
//     Route::get('/main/page/incomplete/persons' , [Customers::class,'getIncompletePersons']);

// });
//end mutual

Route::group(['middleware'=>['auth:sanctum','check.permission:Stock,Stock Inventory Settings','refresh.token.expiry']] , function() {
    Route::get('/products/export-csv' , [Stocks::class,'exportProductsCsv']);
    Route::post('/products/import-csv/preview' , [Stocks::class,'previewProductsCsvImport']);
    Route::post('/products/import-csv' , [Stocks::class,'importProductsCsv']);
    Route::get('/stock/size-option-presets' , [Stocks::class,'sizeOptionPresets']);
    Route::put('/stock/size-option-presets' , [Stocks::class,'updateSizeOptionPresets']);
    Route::get('/store/sections' , [StoreSectionController::class,'index']);
    Route::post('/store/sections' , [StoreSectionController::class,'store']);
    Route::post('/store/sections/update' , [StoreSectionController::class,'update']);
    Route::post('/store/sections/deactivate' , [StoreSectionController::class,'deactivate']);
    Route::post('/store/sections/delete' , [StoreSectionController::class,'destroy']);
    Route::get('/products/by/location' , [StoreSectionController::class,'productsByLocation']);
    Route::post('/products/location/move' , [StoreSectionController::class,'moveProducts']);
    Route::post('/products/location/swap' , [StoreSectionController::class,'swapProductLocations']);
});

Route::group(['middleware'=>['auth:sanctum','check.permission:Stock','refresh.token.expiry']] , function() {

    Route::prefix('meta/catalog')->group(function () {
        Route::get('/status', [MetaCatalogController::class, 'status']);
        Route::get('/products', [MetaCatalogController::class, 'products']);
        Route::get('/sync-log', [MetaCatalogController::class, 'syncLog']);
        Route::get('/product-sets', [MetaCatalogController::class, 'productSets']);
        Route::post('/sync-hierarchy', [MetaCatalogController::class, 'syncHierarchy']);
        Route::post('/queue-hierarchy-sync', [MetaCatalogController::class, 'queueHierarchySync']);
        Route::post('/products/{id}/sync', [MetaCatalogController::class, 'syncProduct']);
        Route::post('/products/{id}/resync', [MetaCatalogController::class, 'resyncProduct']);
        Route::post('/products/{id}/disable', [MetaCatalogController::class, 'disableProduct']);
        Route::post('/variants/{id}/sync', [MetaCatalogController::class, 'syncVariant']);
        Route::post('/variants/{id}/resync', [MetaCatalogController::class, 'syncVariant']);
        Route::post('/variants/{id}/disable', [MetaCatalogController::class, 'disableVariant']);
        Route::post('/bulk-sync', [MetaCatalogController::class, 'bulkSync']);
        Route::post('/test-product', [MetaCatalogController::class, 'testProduct']);
        Route::get('/settings', [MetaCatalogController::class, 'settings']);
        Route::post('/settings', [MetaCatalogController::class, 'saveSettings']);
    });

    Route::get('/get/products/list' , [Stocks::class,'allProducts']);
    Route::get('/get/product/size-options' , [Stocks::class,'productSizeOptions']);
    Route::post('/get/product/details' , [Stocks::class,'showProduct']);
    Route::post('/edit/product' , [Stocks::class,'editProduct']);
    Route::post('/product/cost-price' , [Stocks::class,'updateProductCostPrice']);
    /** إنشاء/تعديل منتج بالحقول الكاملة + صور (مثل صفحة الاختبار): save_scope، وسائط multipart */
    Route::post('/create/product' , [Stocks::class,'createProduct']);
    Route::post('/update/product/full' , [Stocks::class,'updateProductFull']);
    Route::post('/delete/products' , [Stocks::class,'deleteProducts']);
    Route::post('/product/stock/adjust' , [ProductStockController::class,'adjust']);
    Route::post('/product/stock/movements' , [ProductStockController::class,'movements']);
    Route::get('/product/assembly/recipes' , [ProductAssemblyController::class,'recipes']);
    Route::get('/product/assembly/operations' , [ProductAssemblyController::class,'operations']);
    Route::get('/product/assembly/products' , [ProductAssemblyController::class,'products']);
    Route::post('/product/assembly/execute' , [ProductAssemblyController::class,'assemble']);
    Route::post('/product/assembly/disassemble' , [ProductAssemblyController::class,'disassemble']);
    Route::post('/add/product/to/closeouts' , [Stocks::class,'addProductToCloseout']);
    Route::post('/archive/closeout' , [Stocks::class,'archiveCloseout']);

    Route::get('/get/unarchived/closeouts' , [Stocks::class,'getUnArchivedCloseoutes']);
    Route::get('/get/archived/closeouts' , [Stocks::class,'getArchivedCloseoutes']);

    Route::post('/add/combination' , [Stocks::class,'addCombination']);
    Route::get('/get/all/combinations' , [Stocks::class,'getCombinations']);

    Route::get('/offer/packages' , [OfferPackageController::class,'index']);
    Route::get('/offer/packages/show' , [OfferPackageController::class,'show']);
    Route::post('/offer/packages' , [OfferPackageController::class,'store']);
    Route::post('/offer/packages/update' , [OfferPackageController::class,'update']);
    Route::post('/offer/packages/delete' , [OfferPackageController::class,'destroy']);


    Route::post('/search/products/by/name' , [Stocks::class,'searchProduct']);

    Route::get('/product/tags' , [ProductTagController::class,'index']);
    Route::post('/product/tags' , [ProductTagController::class,'store']);
    Route::post('/product/tags/update' , [ProductTagController::class,'update']);
    Route::post('/product/tags/deactivate' , [ProductTagController::class,'deactivate']);
    Route::post('/product/tags/attach' , [ProductTagController::class,'attach']);
    Route::post('/product/tags/detach' , [ProductTagController::class,'detach']);
    Route::get('/products/by/tag' , [ProductTagController::class,'productsByTag']);

});

Route::group(['middleware'=>['auth:sanctum','check.permission:Purchasing Section','refresh.token.expiry']] , function() {
      //bills
    Route::post('/add/bill' , [Bills::class,'createBill']);
    Route::get('/unfinished/bills' , [Bills::class,'getUnfinishedBills']);
    Route::post('/change/product/status' , [Bills::class,'changeProductStatus']);
    Route::post('/get/bill/details' , [Bills::class,'getBillDetails']);
    Route::get('/unmatched/bills' , [Bills::class,'getUnmatchedBills']);
    Route::get('/securities/bills' , [Bills::class,'getSecuritiesBills']);
    Route::get('/finished/bills' , [Bills::class,'getFinishedBills']);
    Route::get('/archived/bills' , [Bills::class,'getArchivedBills']);
    Route::post('/deliver/one/product' , [Bills::class,'deliverOneProduct']);
    Route::post('/purchase/extra/products' , [Bills::class,'purchaseExtraProducts']);
    Route::post('/purchase/new/price' , [Bills::class,'purchaseProdcutsNewPrice']);
    Route::post('/cancel/bill' , [Bills::class,'cancelBill']);
    Route::post('/deliver/whole/bill' , [Bills::class,'deliverBill']);
    Route::post('/bill/report' , [Bills::class,'downloadBill']);



    Route::post('/add/quantity/bill' , [Bills::class,'createBillQuantity']);
   
    // returns
    Route::post('/add/return/purchase' , [ReturnsAPI::class,'createReturnPurchase']);
    Route::get('/get/pending/return/purchases' , [ReturnsAPI::class,'getPendingReturns']);
    Route::get('/get/delivered/return/purchases' , [ReturnsAPI::class,'getDeliveredReturns']);
    Route::post('/change/return/purchase/to/delivered' , [ReturnsAPI::class,'changeToDelivered']);

});

Route::group(['middleware'=>['auth:sanctum','check.permission:Messages Section','refresh.token.expiry']] , function() {
    // WhatsApp Center — admins and employees with Messages Section permission.
    Route::get('/whatsapp/dashboard', [WhatsAppController::class, 'dashboard']);
    Route::get('/whatsapp/conversations', [WhatsAppController::class, 'conversations']);
    Route::get('/whatsapp/conversations/{id}', [WhatsAppController::class, 'showConversation'])->whereNumber('id');
    Route::post('/whatsapp/conversations/{id}/send', [WhatsAppController::class, 'sendToConversation'])->whereNumber('id')->middleware('throttle:20,1');
    Route::post('/whatsapp/conversations/{id}/request-continuation', [WhatsAppController::class, 'requestContinuation'])->whereNumber('id')->middleware('throttle:5,1');
    Route::post('/whatsapp/conversations/{id}/send-media', [WhatsAppController::class, 'sendMediaToConversation'])->whereNumber('id')->middleware('throttle:10,1');
    Route::post('/whatsapp/conversations/{id}/typing', [WhatsAppController::class, 'typing'])->whereNumber('id')->middleware('throttle:12,1');
    Route::get('/whatsapp/products', [WhatsAppController::class, 'products']);
    Route::post('/whatsapp/conversations/{id}/send-products', [WhatsAppController::class, 'sendProducts'])->whereNumber('id')->middleware('throttle:10,1');
    Route::delete('/whatsapp/conversations/{id}/messages/{messageId}', [WhatsAppController::class, 'hideMessage'])->whereNumber(['id', 'messageId']);
    Route::post('/whatsapp/conversations/{id}/link-person', [WhatsAppController::class, 'linkPerson'])->whereNumber('id');
    Route::get('/whatsapp/messages/{id}/media', [WhatsAppController::class, 'media'])->whereNumber('id');
    Route::get('/whatsapp/qr', [WhatsAppController::class, 'qr']);
    Route::get('/whatsapp/qr/a4', [WhatsAppController::class, 'qrA4']);
    Route::post('/whatsapp/send-text', [WhatsAppController::class, 'sendText'])->middleware('throttle:20,1');
    Route::post('/whatsapp/send-template', [WhatsAppController::class, 'sendTemplate'])->middleware('throttle:20,1');
    Route::get('/whatsapp/messages', [WhatsAppController::class, 'messages']);
    Route::get('/whatsapp/templates', [WhatsAppTemplateController::class, 'index']);
    Route::post('/whatsapp/templates', [WhatsAppTemplateController::class, 'store']);
    Route::put('/whatsapp/templates/{id}', [WhatsAppTemplateController::class, 'update'])->whereNumber('id');
    Route::delete('/whatsapp/templates/{id}', [WhatsAppTemplateController::class, 'destroy'])->whereNumber('id');
    Route::get('/whatsapp/settings', [WhatsAppSettingsController::class, 'show']);
    Route::post('/whatsapp/settings', [WhatsAppSettingsController::class, 'store']);
    Route::post('/whatsapp/settings/employees', [WhatsAppSettingsController::class, 'updateEmployees']);
    Route::post('/whatsapp/test-message', [WhatsAppController::class, 'testMessage'])->middleware('throttle:10,1');
});

// admin routes
Route::group(['middleware'=>['auth:sanctum','admin','refresh.token.expiry']] , function() {






    //product dev
    Route::get('/get/all/product/developments' , [ProductDevelopmentApi::class,'allProDevs']);
    Route::post('/update/product/development/step' , [ProductDevelopmentApi::class,'updateDev']);
    Route::post('/create/product/development' , [ProductDevelopmentApi::class,'create']);
    Route::post('/show/product/development' , [ProductDevelopmentApi::class,'showProDev']);
    Route::post('/delete/product/development' , [ProductDevelopmentApi::class,'deleteDev']);


  // reports
    Route::get('/get/all/report/information' , [Reports::class,'mainData']);
    Route::post('/get/reprot/by/type' , [Reports::class,'getReport']);








  //  // orders
  //  Route::get('/all/orders' , [Orders::class,'allOrders']);

  //  //invoices
  //  Route::get('/all/invoices' , [Invoices::class,'allInvoices']);



   // partnerships
   Route::post('/add/partnership' , [Partnerships::class,'createPartnership']);
   Route::get('/ongoing/partnerships' , [Partnerships::class,'getOngoingPartnerships']);
   Route::get('/completed/partnerships' , [Partnerships::class,'getCompletedPartnerships']);
   Route::post('/show/partnership' , [Partnerships::class,'showPartnership']);
   Route::post('/edit/partnership' , [Partnerships::class,'editPartnership']);








   // draws
   Route::post('/add/draw' , [Draws::class,'store']);




    // punishments
  //  Route::post('/add/punishment' , [PunishmentsApi::class,'store']);

  //  // rewards
  //  Route::post('/add/reward' , [RewardsApi::class,'store']);

   //logs
      Route::get('/all/logs' , [Logs::class,'getAllLogs']);
      Route::get('/activity/summary' , [Logs::class,'activitySummary']);
      Route::post('/cancel/log' , [Logs::class,'cancelLog']);
      Route::post('/show/log' , [Logs::class,'showLog']);
      Route::get('/admin/home/page/data' , [Logs::class,'homeData']);

    Route::get('/admin/notifications', [AdminNotificationCenterController::class, 'index']);
    Route::get('/admin/notifications/unread-count', [AdminNotificationCenterController::class, 'unreadCount']);
    Route::post('/admin/notifications/mark-all-read', [AdminNotificationCenterController::class, 'markAllRead']);
    Route::post('/admin/notifications/{id}/read', [AdminNotificationCenterController::class, 'markRead']);
    Route::delete('/admin/notifications/{id}', [AdminNotificationCenterController::class, 'destroy']);
    Route::post('/admin/device-token', [AdminNotificationCenterController::class, 'storeDeviceToken']);
    Route::delete('/admin/device-token', [AdminNotificationCenterController::class, 'destroyDeviceToken']);

    Route::get('/admin/cron-job-logs', [\App\Http\Controllers\API\CronJobLogController::class, 'index']);
    Route::get('/admin/cron-job-logs/{id}', [\App\Http\Controllers\API\CronJobLogController::class, 'show']);

    Route::get('/admin/employee-suggestions', [EmployeeSuggestionsController::class, 'adminIndex']);
    Route::put('/admin/employee-suggestions/{suggestion}', [EmployeeSuggestionsController::class, 'update'])
        ->whereNumber('suggestion');

    Route::post('/admin/impersonate-employee/{employeeId}', [\App\Http\Controllers\API\AdminImpersonationController::class, 'impersonate']);

    Route::get('/admin/users', [\App\Http\Controllers\API\AdminUsersController::class, 'index']);
    Route::post('/admin/users', [\App\Http\Controllers\API\AdminUsersController::class, 'store']);
    Route::post('/admin/users/{id}/edit', [\App\Http\Controllers\API\AdminUsersController::class, 'update'])->whereNumber('id');
    Route::post('/admin/users/{id}/delete', [\App\Http\Controllers\API\AdminUsersController::class, 'destroy'])->whereNumber('id');
    Route::post('/admin/users/{id}/toggle-block', [\App\Http\Controllers\API\AdminUsersController::class, 'toggleBlock'])->whereNumber('id');

    Route::get('/app/settings', [AppSettingsController::class, 'show']);
    Route::put('/app/settings', [AppSettingsController::class, 'update']);

    // Attendance settings (QR/Fingerprint)
    Route::get('/admin/settings/attendance', [AdminAttendanceSettingsController::class, 'show']);
    Route::post('/admin/settings/attendance', [AdminAttendanceSettingsController::class, 'update']);

    // Attendance devices (fingerprint)
    Route::get('/admin/attendance-devices', [AdminAttendanceDevicesController::class, 'index']);
    Route::post('/admin/attendance-devices', [AdminAttendanceDevicesController::class, 'store']);
    Route::put('/admin/attendance-devices/{id}', [AdminAttendanceDevicesController::class, 'update'])->whereNumber('id');
    Route::delete('/admin/attendance-devices/{id}', [AdminAttendanceDevicesController::class, 'destroy'])->whereNumber('id');
    Route::post('/admin/attendance-devices/{id}/test-connection', [AdminAttendanceDevicesController::class, 'testConnection'])->whereNumber('id');
    Route::post('/admin/attendance-devices/{id}/sync-users', [AdminFingerprintDevicesController::class, 'syncUsers'])->whereNumber('id');
    Route::post('/admin/attendance-devices/{id}/sync-logs', [AdminFingerprintDevicesController::class, 'syncLogs'])->whereNumber('id');
    Route::get('/admin/attendance-devices/{id}/users', [AdminFingerprintDevicesController::class, 'users'])->whereNumber('id');
    Route::get('/admin/attendance-devices/{id}/logs', [AdminFingerprintDevicesController::class, 'logs'])->whereNumber('id');
    Route::get('/admin/fingerprint/activity-log', [AdminFingerprintDevicesController::class, 'activityLog']);

    // Fingerprint device users mapping
    Route::get('/admin/fingerprint/users', [AdminFingerprintUsersController::class, 'index']);
    Route::post('/admin/fingerprint/users/{deviceUserId}/link', [AdminFingerprintUsersController::class, 'link']);
    Route::post('/admin/fingerprint/users/{deviceUserId}/unlink', [AdminFingerprintUsersController::class, 'unlink']);

});

   Route::post('/send/notification' , [Notifications::class,'pushNotification']);



   Route::group(['middleware'=>['auth:sanctum','employee','refresh.token.expiry']] , function() {
  
   Route::post('/order/by/employee' , [EmployeeOrders::class,'create']);
      //QR attendence
    Route::post('/qr-scan', [AttendanceController::class, 'scanQr']);

    // employee home page
    Route::get('/employee/home/data', [EmployeeData::class, 'getEmployeeData']);
    Route::get('/employee/my/attendance/history', [EmployeeDetails::class, 'employeeMyAttendanceHistory']);
    Route::get('/get/attendance/details', [EmployeeData::class, 'attendanceReport']);

    Route::get('/employee/notifications', [\App\Http\Controllers\API\EmployeeNotificationCenterController::class, 'index']);
    Route::get('/employee/notifications/unread-count', [\App\Http\Controllers\API\EmployeeNotificationCenterController::class, 'unreadCount']);
    Route::post('/employee/notifications/mark-all-read', [\App\Http\Controllers\API\EmployeeNotificationCenterController::class, 'markAllRead']);
    Route::post('/employee/notifications/{id}/read', [\App\Http\Controllers\API\EmployeeNotificationCenterController::class, 'markRead']);
    Route::delete('/employee/notifications/{id}', [\App\Http\Controllers\API\EmployeeNotificationCenterController::class, 'destroy']);

    Route::get('/employee/reminders', [EmployeeRemindersController::class, 'employeeIndex']);
    Route::post('/employee/reminders/{occurrence}/done', [EmployeeRemindersController::class, 'markDone'])
        ->whereNumber('occurrence');
    Route::post('/employee/reminders/{occurrence}/snooze', [EmployeeRemindersController::class, 'snooze'])
        ->whereNumber('occurrence');

    Route::get('/employee/suggestions', [EmployeeSuggestionsController::class, 'employeeIndex']);
    Route::post('/employee/suggestions', [EmployeeSuggestionsController::class, 'store']);
    Route::put('/employee/suggestions/{suggestion}', [EmployeeSuggestionsController::class, 'employeeUpdate'])
        ->whereNumber('suggestion');
    Route::delete('/employee/suggestions/{suggestion}', [EmployeeSuggestionsController::class, 'employeeDestroy'])
        ->whereNumber('suggestion');

    // employee tasks
    Route::post('/employee/edit/employee/task/images', [EmployeeOwnTasks::class, 'editEmployeeTasksImages']);
    Route::post('/employee/edit/occurrence/task/images', [EmployeeOwnTasks::class, 'editOccurrenceTaskImages']);
    Route::post('/employee/edit/employee/sub/task/images', [EmployeeOwnTasks::class, 'editEmployeeSubTasksImages']);
    Route::post('/employee/edit/occurrence/sub/task/images', [EmployeeOwnTasks::class, 'editOccurrenceSubtaskImages']);

    // employee orders
    Route::post('/employee/add/overtime/order', [OrdersAPI::class, 'createOverTimeOrder']);
    Route::post('/employee/add/loan/order', [OrdersAPI::class, 'createLoanOrder']);
    Route::get('/employee/orders', [OrdersAPI::class, 'getMyOrders']);

    //employee tasks // mw for checking if the subtask belongs to the employee requesting the route
    Route::post('/change/sub/employee/task/to/completed' ,
     [EmployeeTasks::class,'changeSubTaskToCompleted']);
    Route::post('/change/sub/employee/occurrence/task/to/completed',
     [EmployeeTaskOperationsController::class, 'completeOccurrenceSubtask'])
    ;
    Route::post('/change/sub/employee/task/to/pending',
     [EmployeeTasks::class, 'undoSubTaskCompletion']);
    Route::post('/change/sub/employee/occurrence/task/to/pending',
     [EmployeeTaskOperationsController::class, 'undoOccurrenceSubtaskCompletion']);
    Route::post('/change/sub/employee/task/to/rejected' ,
     [EmployeeTasks::class,'rejectSubTask']);
    Route::post('/change/sub/employee/occurrence/task/to/rejected',
     [EmployeeTaskOperationsController::class, 'rejectOccurrenceSubtask']);

    Route::post('/employee/task/start', [EmployeeTaskOperationsController::class, 'startTask']);
    Route::post('/employee/task/submit', [EmployeeTaskOperationsController::class, 'submitTask']);

});


   Route::group(['middleware'=>['auth:sanctum','refresh.token.expiry']] , function() {
        Route::post('/show/employee/task' , [EmployeeTasks::class,'showEmployeeTaskDetails'])
        ->middleware('check.self.owner.or.permission:employeeTask,employee_task_id,Employee Tasks');
    
        Route::post('/change/employee/task/to/completed' , [EmployeeTasks::class,'changeEmployeeTaskToCompleted'])
        ->middleware('check.self.owner.or.permission:employeeTask,employee_task_id,Employee Tasks');
        
          //payment and receive
      Route::post('/add/transaction' , [PaymentAndRecieve::class,'handlePayment']);

   });



    Route::get('/get/all/subcategories' , [Stocks::class,'allSubCategories']);
    Route::get('/get/all/categories' , [Stocks::class,'allCategories']);

    // Category & SubCategory management (admin CRUD)
    Route::get('/admin/categories',                         [CategoryController::class, 'getAllCategories']);
    Route::post('/admin/category/store',                    [CategoryController::class, 'storeCategory']);
    Route::post('/admin/category/update',                   [CategoryController::class, 'updateCategory']);
    Route::post('/admin/category/toggle-status',            [CategoryController::class, 'toggleCategoryStatus']);
    Route::post('/admin/subcategories/by-category',         [CategoryController::class, 'getSubCategoriesByCategory']);
    Route::post('/admin/subcategory/store',                 [CategoryController::class, 'storeSubCategory']);
    Route::post('/admin/subcategory/update',                [CategoryController::class, 'updateSubCategory']);
    Route::post('/admin/subcategory/toggle-status',         [CategoryController::class, 'toggleSubCategoryStatus']);
    Route::get('/get/all/projects' , [Stocks::class,'allProjects']);
    Route::get('/employees' , [EmployeeDetails::class,'employeesList']);
    Route::get('/all/sellers' , [Customers::class,'allSellers']);
    Route::get('/all/customers' , [Customers::class,'allCustomers']);
    Route::get('/get/shown/boxes' , [Boxes::class,'getShownBoxes']);
    Route::get('/all/products' , [Products::class,'allproducts']);
