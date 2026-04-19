<?php

use App\Http\Controllers\API\AssetLogs;
use App\Http\Controllers\API\CategoryController;
use App\Http\Controllers\API\Assets;
use App\Http\Controllers\API\AttendanceController;
use App\Http\Controllers\API\Authentication;
use App\Http\Controllers\API\Bills;
use App\Http\Controllers\API\Boxes;
use App\Http\Controllers\API\BoxLogs;
use App\Http\Controllers\API\Customers;
use App\Http\Controllers\API\Debts;
use App\Http\Controllers\API\Deposits;
use App\Http\Controllers\API\Destructions;
use App\Http\Controllers\API\Draws;
use App\Http\Controllers\API\EmployeeDetails;
use App\Http\Controllers\API\EmployeeOrders;
use App\Http\Controllers\API\Employees\EmployeeData;
use App\Http\Controllers\API\Employees\EmployeeOwnTasks;
use App\Http\Controllers\API\Employees\OrdersAPI;
use App\Http\Controllers\API\EmployeeTasks;
use App\Http\Controllers\API\ExpensesAPI;
use App\Http\Controllers\API\FileBoxes;
use App\Http\Controllers\API\Files;
use App\Http\Controllers\API\FollowupAPI;
use App\Http\Controllers\API\Goals;
use App\Http\Controllers\API\IncomingChecks;
use App\Http\Controllers\API\InstantSales;
use App\Http\Controllers\API\OldInstanBuyingsAPI;
use App\Http\Controllers\API\Invoices;
use App\Http\Controllers\API\LegacyStoreImageController;
use App\Http\Controllers\API\Logs;
use App\Http\Controllers\API\MaintenanceAPI;
use App\Http\Controllers\API\Notifications;
use App\Http\Controllers\API\Orders;
use App\Http\Controllers\API\OutgoingChecks;
use App\Http\Controllers\API\Papers;
use App\Http\Controllers\API\Partners;
use App\Http\Controllers\API\Partnerships;
use App\Http\Controllers\API\PaymentAndRecieve;
use App\Http\Controllers\API\Pictures;
use App\Http\Controllers\API\ProductDevelopmentApi;
use App\Http\Controllers\API\Products;
use App\Http\Controllers\API\Profile;
use App\Http\Controllers\API\ProfitSales;
use App\Http\Controllers\API\Projects;
use App\Http\Controllers\API\PunishmentsApi;
use App\Http\Controllers\API\RewardsApi;
use App\Http\Controllers\API\SpecialTasks;
use App\Models\EmployeeOrder;
use App\Http\Controllers\API\ProjectExpensesAPI;
use App\Http\Controllers\API\Reports;
use App\Http\Controllers\API\ReturnsAPI;
use App\Http\Controllers\API\Stocks;
use App\Http\Controllers\API\Treasuries;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

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

    /** صور المتجر القديم (.NET) — بروكسي لـ Flutter Web (CORS) */
    Route::get('/legacy-store-image', [LegacyStoreImageController::class, 'show']);

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

});

Route::group(['middleware'=>['auth:sanctum','check.permission:Employees Section','refresh.token.expiry']] , function() {
    // employees
    Route::post('/create/employee' , [EmployeeDetails::class,'addEmployee']);
    Route::get('/working/times' , [EmployeeDetails::class,'workingTimes']);
    Route::get('/financial/dues' , [EmployeeDetails::class,'financialDues']);
    Route::post('/edit/employee' , [EmployeeDetails::class,'editEmployee']);
    Route::get('/all/permissions' , [EmployeeDetails::class,'allPermissions']);
    Route::post('/employee/permissions' , [EmployeeDetails::class,'getEmployeePermissions']);

    Route::post('/add/points/to/employee' , [EmployeeDetails::class,'addPoints']);
    Route::post('/minus/points/from/employee' , [EmployeeDetails::class,'minusPoints']);

    Route::post('/show/employee/financial/details' , [EmployeeDetails::class,'showFinancialDetails']);
    Route::post('/pay/employee/salary' , [EmployeeDetails::class,'paySalary']);
    Route::post('/get/employee/financial/data/report' , [EmployeeDetails::class,'employeeReportData']);

    Route::get('/employee/logs' , [Logs::class,'getEmployeesLogs']);


       // employee orders
      Route::get('/employee/loan/orders' , [EmployeeOrders::class,'employeeLoanOrders']);
      Route::get('/employee/overtime/orders' , [EmployeeOrders::class,'employeeOvertimeOrders']);

      Route::post('/approve/employee/loan/order' , [EmployeeOrders::class,'approveLoanRequest']);
      Route::post('/reject/employee/order' , [EmployeeOrders::class,'reject']);
   
      Route::post('/show/employee/loan/order' , [EmployeeOrders::class,'showLoanOrder']);
      Route::post('/show/employee/overtime/order' , [EmployeeOrders::class,'showOvertimeOrder']);
      Route::post('/approve/employee/overtime/order' , [EmployeeOrders::class,'approveOvertimeRequest']);

    // qr generation
    Route::get('/qr-generation', [AttendanceController::class, 'generateQr']);


});


Route::group(['middleware'=>['auth:sanctum','check.permission:Employee Tasks','refresh.token.expiry']] , function() {

      // employee tasks
 
    Route::post('/create/employee/task' , [EmployeeTasks::class,'createEmployeeTask']);
    Route::post('/edit/employee/task' , [EmployeeTasks::class,'updateEmployeeTask']);
 
    Route::get('/employee/completed/tasks' , [EmployeeTasks::class,'completedTasks']);
    Route::get('/employee/ongoing/tasks' , [EmployeeTasks::class,'ongoingTasks']);
    Route::get('/employee/canceled/tasks' , [EmployeeTasks::class,'canceledTasks']);
    Route::post('/cancel/employee/task' , [EmployeeTasks::class,'cancelEmployeeTask']);
    Route::post('/restore/employee/task' , [EmployeeTasks::class,'restoreEmployeeTask']);
    Route::post('/cancel/employee/task/with/repetition' , [EmployeeTasks::class,'cancelEmployeeTaskWithRepetition']);


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

Route::group(['middleware'=>['auth:sanctum','check.permission:General Data,Data Completion','refresh.token.expiry']] , function() {

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
      Route::post('/edit/instant/sale' , [InstantSales::class,'edit']);
    
      Route::post('/get/product/projects' , [InstantSales::class,'getProjectsOfProduct']);
      Route::post('/get/subsales' , [InstantSales::class,'getSubSales']);
      Route::post('/get/instant/sale/invoice' , [InstantSales::class,'invoiceDetails']);

      //Route::post('/attach/project/of/product/to/sale' , [InstantSales::class,'attachProjectToProductInSale']);

    // profit sales
      Route::get('/all/profit/sales' , [ProfitSales::class,'getProfitSales']);
      Route::get('/show/profit/sale' , [ProfitSales::class,'showProfitSale']);
      Route::post('/create/profit/sale' , [ProfitSales::class,'store']);
      Route::post('/edit/profit/sale' , [ProfitSales::class,'edit']);

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

  

});

Route::group(['middleware'=>['auth:sanctum','check.permission:Boxes Section','refresh.token.expiry']] , function() {

     // boxes
   Route::post('/add/box' , [Boxes::class,'addBox']);
   Route::post('/edit/box' , [Boxes::class,'editBox']);
   Route::post('/show/box' , [Boxes::class,'showBox']);
   Route::get('/get/hidden/boxes' , [Boxes::class,'getHiddentBoxes']);
   Route::post('/add/box/balance' , [Boxes::class,'addBalance']);
   Route::post('/transfer/box/balance' , [Boxes::class,'transferBalance']);
   Route::post('/delete/box' , [Boxes::class,'deleteBox']);

  //box logs
  Route::get('/all/box/logs' , [BoxLogs::class,'allBoxLogs']);
  Route::post('/box/logs/report' , [BoxLogs::class,'boxLogsReport']);

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
  });

Route::group(['middleware' => ['auth:sanctum','check.permission:Boxes Section,Checks,Sales,Goal Creation','refresh.token.expiry']], function () {

   Route::get('/get/shown/boxes' , [Boxes::class,'getShownBoxes']);

  });

Route::group(['middleware' => ['auth:sanctum','check.permission:General Data,Debts,Checks,Maintenance,Follow-up Section,Goal Creation,Projects and Purchases Management','refresh.token.expiry']], function () {

    //customers
    Route::get('/all/customers' , [Customers::class,'allCustomers']);
  });

Route::group(['middleware' => ['auth:sanctum','check.permission:General Data,Checks,Debts,Maintenance,Follow-up Section,Goal Creation,Projects and Purchases Management,Purchasing Section','refresh.token.expiry']], function () {

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

Route::group(['middleware'=>['auth:sanctum','check.permission:Stock','refresh.token.expiry']] , function() {

    Route::get('/get/products/list' , [Stocks::class,'allProducts']);
    Route::get('/get/product/size-options' , [Stocks::class,'productSizeOptions']);
    Route::post('/get/product/details' , [Stocks::class,'showProduct']);
    Route::post('/edit/product' , [Stocks::class,'editProduct']);
    /** إنشاء/تعديل منتج بالحقول الكاملة + صور (مثل صفحة الاختبار): save_scope، وسائط multipart */
    Route::post('/create/product' , [Stocks::class,'createProduct']);
    Route::post('/update/product/full' , [Stocks::class,'updateProductFull']);
    Route::post('/add/product/to/closeouts' , [Stocks::class,'addProductToCloseout']);
    Route::post('/archive/closeout' , [Stocks::class,'archiveCloseout']);

    Route::get('/get/unarchived/closeouts' , [Stocks::class,'getUnArchivedCloseoutes']);
    Route::get('/get/archived/closeouts' , [Stocks::class,'getArchivedCloseoutes']);

    Route::post('/add/combination' , [Stocks::class,'addCombination']);
    Route::get('/get/all/combinations' , [Stocks::class,'getCombinations']);


    Route::post('/search/products/by/name' , [Stocks::class,'searchProduct']);

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
// admin routes
Route::group(['middleware'=>['auth:sanctum','admin','refresh.token.expiry']] , function() {






    //product dev
    Route::get('/get/all/product/developments' , [ProductDevelopmentApi::class,'allProDevs']);
    Route::post('/update/product/development/step' , [ProductDevelopmentApi::class,'updateDev']);
    Route::post('/create/product/development' , [ProductDevelopmentApi::class,'create']);
    Route::post('/show/product/development' , [ProductDevelopmentApi::class,'showProDev']);


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
      Route::post('/cancel/log' , [Logs::class,'cancelLog']);
      Route::post('/show/log' , [Logs::class,'showLog']);
      Route::get('/admin/home/page/data' , [Logs::class,'homeData']);



});

   Route::post('/send/notification' , [Notifications::class,'pushNotification']);



   Route::group(['middleware'=>['auth:sanctum','employee','refresh.token.expiry']] , function() {
  
   Route::post('/order/by/employee' , [EmployeeOrders::class,'create']);
      //QR attendence
    Route::post('/qr-scan', [AttendanceController::class, 'scanQr']);

    // employee home page
    Route::get('/employee/home/data', [EmployeeData::class, 'getEmployeeData']);
    Route::get('/get/attendance/details', [EmployeeData::class, 'attendanceReport']);

    // employee tasks
    Route::post('/employee/edit/employee/task/images', [EmployeeOwnTasks::class, 'editEmployeeTasksImages']);
    Route::post('/employee/edit/employee/sub/task/images', [EmployeeOwnTasks::class, 'editEmployeeSubTasksImages']);

    // employee orders
    Route::post('/employee/add/overtime/order', [OrdersAPI::class, 'createOverTimeOrder']);
    Route::post('/employee/add/loan/order', [OrdersAPI::class, 'createLoanOrder']);
    Route::get('/employee/orders', [OrdersAPI::class, 'getMyOrders']);

    //employee tasks // mw for checking if the subtask belongs to the employee requesting the route
    Route::post('/change/sub/employee/task/to/completed' ,
     [EmployeeTasks::class,'changeSubTaskToCompleted'])
    ;


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
