<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;

class AdminSectionAccess
{
    /** Section keys are shared with the admin application's navigation. */
    public const SECTIONS = [
        'employees' => 'الموظفون',
        'employee_tasks' => 'مهام الموظفين',
        'special_tasks' => 'المهام الخاصة',
        'projects' => 'المشاريع',
        'goals' => 'الأهداف',
        'follow_up' => 'المتابعة',
        'debts' => 'الديون',
        'sales' => 'المبيعات',
        'reports' => 'التقارير والإحصائيات',
        'accounting' => 'التقارير المحاسبية',
        'people' => 'البيانات العامة',
        'stock' => 'المخزون',
        'boxes' => 'الصناديق',
        'daily_boxes' => 'الصناديق اليومية',
        'purchases' => 'المشتريات والمرتجعات',
        'finance' => 'الشؤون المالية',
        'maintenance' => 'الصيانة',
        'product_development' => 'تطوير المنتجات',
        'checks' => 'الشيكات والالتزامات',
        'settings' => 'الإعدادات العامة',
        'notification_settings' => 'التحكم بالإشعارات',
        'reminders' => 'تذكيرات الموظفين',
        'suggestions' => 'صندوق الاقتراحات',
        'support' => 'دعم الموظفين',
        'store_support' => 'دعم المتجر',
        'notes' => 'الملاحظات',
        'smart_home' => 'المنزل الذكي',
        'app_development' => 'تطوير التطبيق',
        'social' => 'مركز التواصل الاجتماعي',
        'social_catalog' => 'كتالوج التواصل الاجتماعي',
        'online_store' => 'إدارة المتجر الإلكتروني',
    ];

    private const CONTROLLERS = [
        'employees' => ['EmployeeDetails', 'EmployeeOrders', 'EmployeeActivityLogController', 'EmployeePointsController', 'EmployeeRewardRuleController', 'EmployeePointCategoryController', 'EmployeePointRuleController', 'EmployeeAttendanceReportController', 'AdminEmployeeAttendanceController', 'EmployeeAttendanceOvertimeController', 'SalaryPayrollController', 'EmployeeSignatureController', 'EmployeePerformanceController', 'AttendanceController'],
        'employee_tasks' => ['EmployeeTasks', 'EmployeeTaskOperationsController'],
        'special_tasks' => ['SpecialTasks'],
        'projects' => ['Projects', 'ProjectExpensesAPI'],
        'goals' => ['Goals'],
        'follow_up' => ['FollowupAPI'],
        'debts' => ['Debts', 'DebtLedger'],
        'sales' => ['InstantSales', 'SalesOrdersController', 'SalesReturnsController', 'SuspendedInstantSaleController', 'SalesSettingsController', 'ProfitSales', 'Orders', 'ShiplyController', 'DeliveryCompanyAccountsController', 'DeliveryCompaniesController'],
        'reports' => ['Reports'],
        'accounting' => ['AccountingController', 'SalesAccountingReportController', 'FinancialAccountingReportController'],
        'people' => ['Customers', 'Partners', 'Partnerships', 'PersonProfileController', 'PersonProductSettingsController', 'PartnerAddressesController'],
        'stock' => ['Stocks', 'Products', 'ProductStockController', 'ProductAssemblyController', 'CategoryController', 'ProductTagController', 'StoreSectionController', 'Pictures', 'LegacyStoreImageController'],
        'boxes' => ['Boxes', 'BoxLogs', 'FileBoxes', 'Files', 'Treasuries', 'Deposits', 'Draws', 'BanksController'],
        'daily_boxes' => ['SalesDailySessionController'],
        'purchases' => ['Bills', 'Invoices', 'OldInstanBuyingsAPI', 'ReturnsAPI', 'PurchaseReturnsController'],
        'finance' => ['ExpensesAPI', 'Assets', 'AssetLogs', 'Destructions', 'Papers'],
        'maintenance' => ['MaintenanceAPI', 'MaintenanceServiceController'],
        'product_development' => ['ProductDevelopmentApi'],
        'checks' => ['IncomingChecks', 'OutgoingChecks', 'CheckNotificationRulesController'],
        'settings' => ['AppSettingsController', 'AdminAttendanceSettingsController', 'AdminAttendanceDevicesController', 'AdminFingerprintDevicesController', 'AdminFingerprintUsersController', 'CitiesController', 'StoreSectionController', 'BanksController'],
        'notification_settings' => ['AdminNotificationSettingsController'],
        'reminders' => ['EmployeeRemindersController'],
        'suggestions' => ['EmployeeSuggestionsController'],
        'notes' => ['NotesController'],
        'smart_home' => ['SmartHomeController', 'SmartSceneController'],
        'app_development' => ['AppDevelopmentTaskController', 'AppVersionController', 'AppUpdateController'],
        'social' => ['SocialCenterController', 'WhatsAppController', 'WhatsAppTemplateController', 'WhatsAppSettingsController'],
        'social_catalog' => ['MetaCatalogController'],
    ];

    public function allows(User $user, Request $request): bool
    {
        if ($user->type !== 'admin' || $user->isFullAdmin()) {
            return true;
        }

        $route = $request->route();
        $action = $route?->getActionName() ?? '';
        [$class, $method] = array_pad(explode('@', $action), 2, '__invoke');
        $controller = class_basename($class);

        // Account and permission administration always requires a full manager.
        if (in_array($controller, ['AdminUsersController', 'AdminSectionPermissionsController', 'EmployeePermissionDelegationController', 'EmployeeImpersonationController', 'AdminImpersonationController', 'DatabaseBackupController', 'AdminPasswordResetCodeController'], true)
            || ($controller === 'EmployeeDetails' && in_array($method, ['addEmployee', 'editEmployee', 'updatePermissionGrantPolicy'], true))) {
            return false;
        }

        if (($controller === 'Authentication' && in_array($method, ['me', 'logout', 'changePassword', 'updateFcmToken'], true))
            || $controller === 'Profile' || $controller === 'AdminUiPreferencesController'
            || ($controller === 'AppSettingsController' && $method === 'show')
            || ($controller === 'AppUpdateController' && $method === 'check')
            || ($controller === 'AppVersionController' && $method === 'seen')) {
            return true;
        }

        // A restricted admin must not receive cross-section totals or activity.
        if ($controller === 'Logs' || $controller === 'AdminNotificationCenterController') {
            return $controller === 'Logs' && $method === 'getEmployeesLogs' && $user->canAccessAdminSection('employees');
        }

        if (str_contains($class, '\\API\\OnlineStore\\') || str_contains($class, '\\API\\Store\\')) {
            return $user->canAccessAdminSection('online_store');
        }

        if (in_array($controller, ['SupportConversationController', 'SupportAttachmentController'], true)) {
            // SupportAccessService also checks the conversation's actual scope.
            return $user->canAccessAdminSection('support') || $user->canAccessAdminSection('store_support');
        }

        if ($controller === 'TaskConversionController') {
            return $user->canAccessAdminSection('employee_tasks') && $user->canAccessAdminSection('special_tasks');
        }
        if ($controller === 'SalesDailySessionController' && in_array($method, ['current', 'open', 'requestClosing', 'requestCancellation', 'requestReopen', 'closePayload', 'show'], true)) {
            return $user->canAccessAdminSection('sales') || $user->canAccessAdminSection('daily_boxes');
        }
        if ($controller === 'OfferPackageController') {
            return $user->canAccessAdminSection($method === 'forSale' ? 'sales' : 'stock');
        }

        // Read-only selectors are dependencies of several operational sections.
        $selectors = [
            'Products@allproducts' => ['stock', 'sales', 'purchases', 'maintenance', 'online_store', 'social_catalog', 'product_development'],
            'Stocks@allCategories' => ['stock', 'sales', 'purchases', 'online_store'],
            'Stocks@allSubCategories' => ['stock', 'sales', 'purchases', 'online_store'],
            'Stocks@searchProduct' => ['stock', 'sales', 'purchases', 'maintenance'],
            'Stocks@allProducts' => ['stock', 'sales', 'purchases', 'maintenance', 'online_store'],
            'Stocks@showProduct' => ['stock', 'sales', 'purchases', 'maintenance', 'online_store'],
            'Stocks@productSizeOptions' => ['stock', 'sales', 'purchases'],
            'Stocks@allProjects' => ['stock', 'projects', 'finance'],
            'Stocks@createPurchaseProduct' => ['purchases'],
            'Customers@allCustomers' => ['people', 'sales', 'debts', 'maintenance', 'checks', 'online_store'],
            'Customers@allSellers' => ['people', 'purchases', 'sales', 'debts', 'checks'],
            'EmployeeDetails@employeesList' => ['employees', 'employee_tasks', 'special_tasks', 'projects', 'goals', 'reminders', 'suggestions', 'smart_home'],
            'EmployeeDetails@allPermissions' => ['employees'],
            'Boxes@getShownBoxes' => ['boxes', 'sales', 'purchases', 'finance', 'checks', 'maintenance', 'daily_boxes', 'employees'],
            'BanksController@index' => ['boxes', 'sales', 'purchases', 'checks', 'settings'],
            'PaymentAndRecieve@handlePayment' => ['debts', 'boxes', 'sales', 'purchases', 'finance'],
        ];
        foreach ($selectors[$controller.'@'.$method] ?? [] as $section) {
            if ($user->canAccessAdminSection($section)) {
                return true;
            }
        }

        foreach (self::CONTROLLERS as $section => $controllers) {
            if (in_array($controller, $controllers, true) && $user->canAccessAdminSection($section)) {
                return true;
            }
        }

        // New/unclassified APIs are closed to restricted admins by default.
        return false;
    }
}
