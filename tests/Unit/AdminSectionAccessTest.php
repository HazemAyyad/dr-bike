<?php

namespace Tests\Unit;

use App\Models\SupportConversation;
use App\Models\User;
use App\Services\AdminSectionAccess;
use App\Services\Support\SupportAccessService;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use PHPUnit\Framework\TestCase;

class AdminSectionAccessTest extends TestCase
{
    private function admin(?array $sections): User
    {
        return (new User)->forceFill(['id' => 20, 'type' => 'admin', 'admin_sections' => $sections]);
    }

    private function allows(?array $sections, string $controller, string $method): bool
    {
        $request = Request::create('/api/test', 'GET');
        $action = 'App\\Http\\Controllers\\API\\'.$controller.'@'.$method;
        $route = new Route('GET', 'api/test', ['uses' => $action, 'controller' => $action]);
        $request->setRouteResolver(fn () => $route);

        return (new AdminSectionAccess)->allows($this->admin($sections), $request);
    }

    public function test_existing_managers_keep_full_access_and_an_empty_list_grants_none(): void
    {
        $this->assertTrue($this->admin(null)->isFullAdmin());
        $this->assertFalse($this->admin([])->isFullAdmin());
        $this->assertTrue($this->allows(null, 'AdminUsersController', 'store'));
        $this->assertFalse($this->allows([], 'InstantSales', 'allInstantSales'));
    }

    public function test_sections_protect_reads_writes_and_unclassified_apis(): void
    {
        $this->assertTrue($this->allows(['sales'], 'InstantSales', 'createInstantSale'));
        $this->assertTrue($this->allows(['stock'], 'Stocks', 'editProduct'));
        $this->assertFalse($this->allows(['sales'], 'Stocks', 'editProduct'));
        $this->assertFalse($this->allows(['stock'], 'InstantSales', 'createInstantSale'));
        $this->assertFalse($this->allows(['sales'], 'FutureController', 'export'));
    }

    public function test_restricted_admin_cannot_escalate_even_with_every_section(): void
    {
        $sections = array_keys(AdminSectionAccess::SECTIONS);
        foreach ([
            ['AdminUsersController', 'store'],
            ['AdminUsersController', 'updateDevelopmentRole'],
            ['AdminSectionPermissionsController', 'update'],
            ['EmployeePermissionDelegationController', 'sync'],
            ['EmployeeDetails', 'addEmployee'],
            ['EmployeeDetails', 'editEmployee'],
            ['EmployeeDetails', 'updatePermissionGrantPolicy'],
            ['AdminImpersonationController', 'impersonate'],
            ['EmployeeImpersonationController', 'start'],
            ['DatabaseBackupController', 'download'],
        ] as [$controller, $method]) {
            $this->assertFalse($this->allows($sections, $controller, $method), $controller.'@'.$method);
        }
    }

    public function test_selectors_work_without_granting_access_to_stock_or_people_management(): void
    {
        $this->assertTrue($this->allows(['sales'], 'Products', 'allproducts'));
        $this->assertTrue($this->allows(['sales'], 'Customers', 'allCustomers'));
        $this->assertFalse($this->allows(['sales'], 'Customers', 'deleteCustomer'));
        $this->assertTrue($this->allows(['sales'], 'Stocks', 'allProducts'));
        $this->assertFalse($this->allows(['sales'], 'Stocks', 'deleteProducts'));
    }

    public function test_account_endpoints_remain_accessible_without_any_sections(): void
    {
        foreach (['me', 'logout', 'changePassword', 'updateFcmToken'] as $method) {
            $this->assertTrue($this->allows([], 'Authentication', $method));
        }
        $this->assertTrue($this->allows([], 'Profile', 'updatePersonalInformation'));
        $this->assertTrue($this->allows([], 'AppSettingsController', 'show'));
        $this->assertFalse($this->allows([], 'AppSettingsController', 'update'));
    }

    public function test_restricted_admin_does_not_receive_cross_section_summaries(): void
    {
        $this->assertFalse($this->allows(['sales'], 'Logs', 'homeData'));
        $this->assertFalse($this->allows(['reports'], 'Logs', 'activitySummary'));
        $this->assertFalse($this->allows(['notification_settings'], 'AdminNotificationCenterController', 'index'));
    }

    public function test_store_support_and_employee_support_are_separate(): void
    {
        $access = new SupportAccessService;
        $storeAdmin = $this->admin(['store_support']);
        $this->assertTrue($access->canManageStore($storeAdmin));
        $this->assertFalse($access->canManageEmployee($storeAdmin));
        $employeeAdmin = $this->admin(['support']);
        $this->assertTrue($access->canManageEmployee($employeeAdmin));
        $this->assertFalse($access->canManageStore($employeeAdmin));
        $conversation = (new SupportConversation)->forceFill(['source' => 'online_store', 'requester_user_id' => 5]);
        $this->assertFalse($access->canAccess($employeeAdmin, $conversation));
    }

    public function test_section_admins_cannot_use_development_role_as_full_manager_access(): void
    {
        $user = $this->admin(['employees']);
        $user->development_role = User::DEVELOPMENT_ROLE_OWNER;
        $this->assertFalse($user->isFullAdmin());
        $this->assertFalse($user->canAccessAdminSection('sales'));
    }
}
