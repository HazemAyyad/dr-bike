<?php

namespace Tests\Unit;

use App\Models\EmployeeDetail;
use App\Models\EmployeePermission;
use App\Models\Permission;
use App\Models\User;
use App\Services\EmployeePermissionDelegationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EmployeePermissionDelegationServiceTest extends TestCase
{
    private EmployeePermissionDelegationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        DB::reconnect('sqlite');

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->string('type')->default('employee');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('name_en')->unique();
            $table->string('grant_policy')->default('permissions_manage');
            $table->timestamps();
        });
        Schema::create('employee_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->boolean('can_delegate_permissions')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('employee_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employee_details');
            $table->foreignId('permission_id')->constrained('permissions');
            $table->timestamps();
            $table->unique(['employee_id', 'permission_id']);
        });
        Schema::create('employee_permission_audits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->unsignedBigInteger('target_employee_id');
            $table->unsignedBigInteger('permission_id')->nullable();
            $table->string('action');
            $table->json('metadata')->nullable();
            $table->string('ip_address')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        $this->service = app(EmployeePermissionDelegationService::class);
    }

    public function test_delegator_can_only_change_permissions_they_hold_and_other_target_permissions_are_preserved(): void
    {
        [$actor, $actorEmployee] = $this->employee('actor@example.com', true);
        [, $target] = $this->employee('target@example.com');
        $access = $this->permission('مشاهدة الموظفين', 'Employees View');
        $owned = $this->permission('صلاحية يملكها', 'Owned Permission');
        $other = $this->permission('صلاحية أخرى', 'Other Permission');
        $this->assign($actorEmployee, $access, $owned);
        $this->assign($target, $other);

        $result = $this->service->sync($actor, $target, [$owned->id], '127.0.0.1');

        $this->assertSame([$owned->id], $result['added_permission_ids']);
        $this->assertEqualsCanonicalizing(
            [$owned->id, $other->id],
            EmployeePermission::where('employee_id', $target->id)->pluck('permission_id')->all()
        );
        $this->assertDatabaseHas('employee_permission_audits', [
            'actor_user_id' => $actor->id,
            'target_employee_id' => $target->id,
            'permission_id' => $owned->id,
            'action' => 'granted',
        ]);
    }

    public function test_delegator_cannot_add_a_permission_they_do_not_hold(): void
    {
        [$actor, $actorEmployee] = $this->employee('actor2@example.com', true);
        [, $target] = $this->employee('target2@example.com');
        $access = $this->permission('مشاهدة الموظفين', 'Employees View');
        $notOwned = $this->permission('غير مملوكة', 'Not Owned');
        $this->assign($actorEmployee, $access);

        $this->expectException(AuthorizationException::class);
        try {
            $this->service->sync($actor, $target, [$notOwned->id]);
        } finally {
            $this->assertDatabaseMissing('employee_permissions', [
                'employee_id' => $target->id,
                'permission_id' => $notOwned->id,
            ]);
        }
    }

    public function test_admin_only_permission_cannot_be_delegated_by_an_employee_who_holds_it(): void
    {
        [$actor, $actorEmployee] = $this->employee('actor3@example.com', true);
        [, $target] = $this->employee('target3@example.com');
        $access = $this->permission('مشاهدة الموظفين', 'Employees View');
        $sensitive = $this->permission('حساسة', 'Sensitive', 'admin_only');
        $this->assign($actorEmployee, $access, $sensitive);

        $this->expectException(AuthorizationException::class);
        $this->service->sync($actor, $target, [$sensitive->id]);
    }

    public function test_employee_without_delegation_switch_cannot_modify_permissions(): void
    {
        [$actor, $actorEmployee] = $this->employee('actor4@example.com', false);
        [, $target] = $this->employee('target4@example.com');
        $access = $this->permission('مشاهدة الموظفين', 'Employees View');
        $this->assign($actorEmployee, $access);

        $this->expectException(AuthorizationException::class);
        $this->service->sync($actor, $target, []);
    }

    public function test_employee_cannot_manage_their_own_permissions(): void
    {
        [$actor, $actorEmployee] = $this->employee('actor5@example.com', true);
        $access = $this->permission('مشاهدة الموظفين', 'Employees View');
        $this->assign($actorEmployee, $access);

        $this->expectException(AuthorizationException::class);
        $this->service->sync($actor, $actorEmployee, [$access->id]);
    }

    public function test_legacy_financial_parent_permission_is_not_delegable(): void
    {
        [$actor, $actorEmployee] = $this->employee('actor6@example.com', true);
        [, $target] = $this->employee('target6@example.com');
        $access = $this->permission('مشاهدة الموظفين', 'Employees View');
        $legacy = $this->permission('المصاريف والأمور المالية', 'Expenses and Financial Affairs');
        $this->assign($actorEmployee, $access, $legacy);

        $this->expectException(AuthorizationException::class);
        $this->service->sync($actor, $target, [$legacy->id]);
    }

    /** @return array{User, EmployeeDetail} */
    private function employee(string $email, bool $canDelegate = false): array
    {
        $user = User::query()->create([
            'name' => $email,
            'email' => $email,
            'type' => 'employee',
        ]);
        $employee = EmployeeDetail::query()->create([
            'user_id' => $user->id,
            'can_delegate_permissions' => $canDelegate,
        ]);
        $user->setRelation('employee', $employee);

        return [$user, $employee];
    }

    private function permission(string $name, string $nameEn, string $policy = 'permissions_manage'): Permission
    {
        return Permission::query()->create([
            'name' => $name,
            'name_en' => $nameEn,
            'grant_policy' => $policy,
        ]);
    }

    private function assign(EmployeeDetail $employee, Permission ...$permissions): void
    {
        foreach ($permissions as $permission) {
            EmployeePermission::query()->create([
                'employee_id' => $employee->id,
                'permission_id' => $permission->id,
            ]);
        }
    }
}
