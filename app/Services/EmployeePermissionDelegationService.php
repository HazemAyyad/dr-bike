<?php

namespace App\Services;

use App\Models\EmployeeDetail;
use App\Models\EmployeePermission;
use App\Models\EmployeePermissionAudit;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EmployeePermissionDelegationService
{
    private const ADMIN_ONLY = 'admin_only';

    /** @var string[] */
    private const TARGET_ACCESS_PERMISSIONS = [
        'Employees Section',
        'Employees View',
        'Employees Edit Basic',
        'Employees Permissions View',
        'Employees Permissions Manage',
    ];

    public function actorCanDelegate(User $actor): bool
    {
        if ($actor->type === 'admin') {
            return true;
        }

        return $actor->type === 'employee' &&
            (bool) ($actor->employee?->can_delegate_permissions ?? false);
    }

    public function actorCanAccessTarget(User $actor, EmployeeDetail $target): bool
    {
        if ($actor->type === 'admin') {
            return true;
        }

        $actorEmployee = $actor->employee;
        if (! $actorEmployee || (int) $actorEmployee->id === (int) $target->id) {
            return false;
        }

        return $actorEmployee->permissions()
            ->whereHas('permission', fn ($query) => $query->whereIn('name_en', self::TARGET_ACCESS_PERMISSIONS))
            ->exists();
    }

    /** @return int[] */
    public function grantablePermissionIds(User $actor): array
    {
        if ($actor->type === 'admin') {
            return Permission::query()
                ->where('name_en', '!=', 'Expenses and Financial Affairs')
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        if (! $this->actorCanDelegate($actor) || ! $actor->employee) {
            return [];
        }

        return $actor->employee->permissions()
            ->whereHas('permission', function ($query) {
                $query->where('name_en', '!=', 'Expenses and Financial Affairs');
                if (Schema::hasColumn('permissions', 'grant_policy')) {
                    $query->where(function ($policy) {
                        $policy->whereNull('grant_policy')
                            ->orWhere('grant_policy', '!=', self::ADMIN_ONLY);
                    });
                }
            })
            ->pluck('permission_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    public function context(User $actor, EmployeeDetail $target): array
    {
        $this->authorize($actor, $target);

        $selectedIds = $target->permissions()
            ->pluck('permission_id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $editableIds = $this->grantablePermissionIds($actor);

        $query = Permission::query()
            ->where('name_en', '!=', 'Expenses and Financial Affairs')
            ->orderBy('id');
        if ($actor->type !== 'admin') {
            $query->whereIn('id', $editableIds);
        }

        $columns = ['id', 'name', 'name_en'];
        if (Schema::hasColumn('permissions', 'grant_policy')) {
            $columns[] = 'grant_policy';
        }

        return [
            'target_employee_id' => (int) $target->id,
            'target_employee_name' => $target->user?->name ?? '',
            'can_delegate_permissions' => (bool) ($target->can_delegate_permissions ?? false),
            'permissions' => $query->get($columns)->map(function (Permission $permission) use ($selectedIds, $editableIds) {
                $groupKey = $this->groupKey($permission->name_en);

                return [
                    'permission_id' => (int) $permission->id,
                    'permission_name' => $permission->name,
                    'permission_name_en' => $permission->name_en,
                    'grant_policy' => $permission->grant_policy ?? 'permissions_manage',
                    'admin_only' => ($permission->grant_policy ?? null) === self::ADMIN_ONLY,
                    'group_key' => $groupKey,
                    'group_name' => $this->groupName($groupKey),
                    'selected' => in_array((int) $permission->id, $selectedIds, true),
                    'editable' => in_array((int) $permission->id, $editableIds, true),
                ];
            })->values(),
        ];
    }

    /**
     * @param int[] $requestedPermissionIds
     * @return int[]
     */
    public function sync(User $actor, EmployeeDetail $target, array $requestedPermissionIds, ?string $ipAddress = null): array
    {
        $this->authorize($actor, $target);
        $requestedPermissionIds = collect($requestedPermissionIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        return DB::transaction(function () use ($actor, $target, $requestedPermissionIds, $ipAddress) {
            EmployeeDetail::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();

            $existingIds = EmployeePermission::query()
                ->where('employee_id', $target->id)
                ->pluck('permission_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();
            $editableIds = $this->grantablePermissionIds($actor);

            $attemptedUnauthorizedAdds = array_diff($requestedPermissionIds, $editableIds, $existingIds);
            if ($actor->type !== 'admin' && $attemptedUnauthorizedAdds !== []) {
                throw new AuthorizationException('لا يمكنك منح صلاحية غير موجودة لديك.');
            }

            $desiredEditableIds = array_values(array_intersect($requestedPermissionIds, $editableIds));
            $toAdd = array_values(array_diff($desiredEditableIds, $existingIds));
            $toDelete = array_values(array_intersect(array_diff($existingIds, $desiredEditableIds), $editableIds));

            if ($toDelete !== []) {
                EmployeePermission::query()
                    ->where('employee_id', $target->id)
                    ->whereIn('permission_id', $toDelete)
                    ->delete();
            }
            foreach ($toAdd as $permissionId) {
                EmployeePermission::query()->firstOrCreate([
                    'employee_id' => $target->id,
                    'permission_id' => $permissionId,
                ]);
            }

            $this->auditMany($actor, $target, $toAdd, 'granted', $ipAddress);
            $this->auditMany($actor, $target, $toDelete, 'revoked', $ipAddress);

            return [
                'added_permission_ids' => $toAdd,
                'removed_permission_ids' => $toDelete,
                'permission_ids' => EmployeePermission::query()
                    ->where('employee_id', $target->id)
                    ->pluck('permission_id')
                    ->map(fn ($id) => (int) $id)
                    ->values()
                    ->all(),
            ];
        });
    }

    public function setDelegation(User $actor, EmployeeDetail $target, bool $enabled, ?string $ipAddress = null): void
    {
        if ($actor->type !== 'admin') {
            throw new AuthorizationException('هذه الخاصية متاحة للأدمن فقط.');
        }

        $previous = (bool) ($target->can_delegate_permissions ?? false);
        $target->forceFill(['can_delegate_permissions' => $enabled])->save();

        if (Schema::hasTable('employee_permission_audits') && $previous !== $enabled) {
            EmployeePermissionAudit::query()->create([
                'actor_user_id' => $actor->id,
                'target_employee_id' => $target->id,
                'action' => $enabled ? 'delegation_enabled' : 'delegation_disabled',
                'metadata' => ['before' => $previous, 'after' => $enabled],
                'ip_address' => $ipAddress,
                'created_at' => now(),
            ]);
        }
    }

    private function authorize(User $actor, EmployeeDetail $target): void
    {
        if (! $this->actorCanDelegate($actor)) {
            throw new AuthorizationException('غير مسموح لك بتفويض صلاحيات الموظفين.');
        }
        if (! $this->actorCanAccessTarget($actor, $target)) {
            throw new AuthorizationException('لا يمكنك إدارة صلاحيات هذا الموظف.');
        }
    }

    /** @param int[] $permissionIds */
    private function auditMany(User $actor, EmployeeDetail $target, array $permissionIds, string $action, ?string $ipAddress): void
    {
        if (! Schema::hasTable('employee_permission_audits')) {
            return;
        }

        foreach ($permissionIds as $permissionId) {
            EmployeePermissionAudit::query()->create([
                'actor_user_id' => $actor->id,
                'target_employee_id' => $target->id,
                'permission_id' => $permissionId,
                'action' => $action,
                'ip_address' => $ipAddress,
                'created_at' => now(),
            ]);
        }
    }

    private function groupKey(?string $name): string
    {
        return match ($name) {
            'Financial Expenses View', 'Financial Expenses Create', 'Financial Expenses Edit',
            'Financial Expenses Reports', 'Financial Destructions View', 'Financial Destructions Manage',
            'Financial Assets View', 'Financial Assets Manage', 'Financial Assets Delete',
            'Financial Assets Depreciate', 'Financial Assets Reports', 'Financial Official Papers View',
            'Financial Official Papers Manage', 'Financial Official Papers Delete',
            'Expenses and Financial Affairs', 'Debts', 'Boxes Section', 'Daily Boxes' => 'financial',
            'Employees Section', 'Employees View', 'Employees Edit Basic', 'Employees Permissions View',
            'Employees Permissions Manage' => 'employees',
            'Sales', 'Sales Settings', 'Delivery Company Accounts' => 'sales',
            'Stock', 'Purchasing Section', 'Cost Price', 'View Inventory Cost', 'Adjust Stock',
            'Adjust Inventory Cost', 'Manage Purchases' => 'stock',
            default => 'general',
        };
    }

    private function groupName(string $key): string
    {
        return match ($key) {
            'financial' => 'المالية والصناديق',
            'employees' => 'الموظفين',
            'sales' => 'المبيعات',
            'stock' => 'المخزون والمشتريات',
            default => 'إعدادات عامة',
        };
    }
}
