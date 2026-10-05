<?php

namespace Tests\Feature\OnlineStore;

use App\Http\Middleware\RefreshSanctumTokenExpiry;
use App\Models\EmployeeDetail;
use App\Models\EmployeePermission;
use App\Models\Permission;
use App\Models\User;
use App\Policies\OnlineStore\OnlineStorePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\Support\OnlineStoreCreditFixture;
use Tests\Support\OnlineStoreFixtureFactory;
use Tests\TestCase;

class OnlineStorePermissionTest extends TestCase
{
    use RefreshDatabase {
        refreshTestDatabase as baseRefreshTestDatabase;
    }

    private const CAPABILITIES = [
        'Online Store View',
        'Online Store Products Manage',
        'Online Store Categories Manage',
        'Online Store Content Manage',
        'Online Store Promotions Manage',
        'Online Store Reviews Manage',
        'Online Store Settings Manage',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        if (filter_var(env('ONLINE_STORE_DUMP_DERIVED_SCHEMA_READY', false), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Fresh-schema tests cannot alter restored dump-derived state.');
        }
    }

    protected function refreshTestDatabase(): void
    {
        if (filter_var(env('ONLINE_STORE_PREMIGRATED_SCHEMA_READY', false), FILTER_VALIDATE_BOOL)) {
            $this->beginDatabaseTransaction();

            return;
        }

        if (! filter_var(env('ONLINE_STORE_DUMP_DERIVED_SCHEMA_READY', false), FILTER_VALIDATE_BOOL)) {
            $this->baseRefreshTestDatabase();
        }
    }

    public function test_all_seven_capabilities_are_independent_and_admin_bypasses_them(): void
    {
        $policy = app(OnlineStorePolicy::class);
        $admin = new User(['type' => 'admin']);
        foreach (self::CAPABILITIES as $permission) {
            $this->assertTrue($policy->authorize($admin, $permission)->allowed());
        }

        foreach (self::CAPABILITIES as $granted) {
            $employee = Mockery::mock(User::class)->makePartial();
            $employee->type = 'employee';
            $employee->shouldReceive('hasEmployeePermission')->andReturnUsing(fn (...$requested) => in_array($granted, $requested, true));
            foreach (self::CAPABILITIES as $permission) {
                $this->assertSame($permission === $granted, $policy->authorize($employee, $permission)->allowed());
            }
        }
    }

    public function test_direct_url_denial_and_inaccessible_resource_use_server_side_404(): void
    {
        $fixture = OnlineStoreCreditFixture::create();
        $employee = OnlineStoreFixtureFactory::createStoreActor(['type' => 'employee']);
        Sanctum::actingAs($employee);

        $this->getJson('/api/online-store/account-links/'.$fixture['link']->id.'/credit')->assertNotFound();
    }

    public function test_authorized_employee_can_use_direct_view_endpoint(): void
    {
        $employee = OnlineStoreFixtureFactory::createStoreActor(['type' => 'employee']);
        $details = EmployeeDetail::query()->create(['user_id' => $employee->id]);
        $permission = Permission::query()->firstOrCreate(
            ['name_en' => 'Online Store View'],
            ['name' => 'Online Store View'],
        );
        EmployeePermission::query()->create(['employee_id' => $details->id, 'permission_id' => $permission->id]);
        Sanctum::actingAs($employee);
        $this->withoutMiddleware(RefreshSanctumTokenExpiry::class);

        $this->getJson('/api/online-store/dashboard')->assertOk()->assertJsonStructure(['data']);
    }

    public function test_account_link_endpoints_require_the_exact_settings_permission_and_hide_direct_resources(): void
    {
        $fixture = OnlineStoreCreditFixture::create();
        $employee = OnlineStoreFixtureFactory::createStoreActor(['type' => 'employee']);
        $details = EmployeeDetail::query()->create(['user_id' => $employee->id]);
        $viewPermission = Permission::query()->firstOrCreate(
            ['name_en' => 'Online Store View'],
            ['name' => 'Online Store View'],
        );
        EmployeePermission::query()->create(['employee_id' => $details->id, 'permission_id' => $viewPermission->id]);
        Sanctum::actingAs($employee);
        $this->withoutMiddleware(RefreshSanctumTokenExpiry::class);

        $this->getJson('/api/online-store/accounts')->assertForbidden();
        $this->getJson('/api/online-store/account-links')->assertForbidden();
        $this->patchJson('/api/online-store/account-links/'.$fixture['link']->id, [
            'status' => 'suspended',
        ])->assertNotFound();

        $settingsPermission = Permission::query()->firstOrCreate(
            ['name_en' => 'Online Store Settings Manage'],
            ['name' => 'Online Store Settings Manage'],
        );
        EmployeePermission::query()->create(['employee_id' => $details->id, 'permission_id' => $settingsPermission->id]);
        $employee->unsetRelation('employee');

        $this->getJson('/api/online-store/accounts')
            ->assertOk()
            ->assertJsonStructure(['data']);
        $this->getJson('/api/online-store/account-links')
            ->assertOk()
            ->assertJsonStructure(['data']);
        $this->patchJson('/api/online-store/account-links/'.$fixture['link']->id, [
            'status' => 'suspended',
        ])->assertOk()->assertJsonPath('data.status', 'suspended');
    }
}
