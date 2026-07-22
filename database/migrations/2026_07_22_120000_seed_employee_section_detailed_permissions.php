<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var array<int, array{id: int, name: string, name_en: string}>
     */
    private array $permissions = [
        ['id' => 50, 'name' => 'مشاهدة الموظفين', 'name_en' => 'Employees View'],
        ['id' => 51, 'name' => 'إضافة موظف', 'name_en' => 'Employees Create'],
        ['id' => 52, 'name' => 'تعديل بيانات الموظف', 'name_en' => 'Employees Edit Basic'],
        ['id' => 53, 'name' => 'حذف موظف', 'name_en' => 'Employees Delete'],
        ['id' => 54, 'name' => 'مشاهدة صلاحيات الموظفين', 'name_en' => 'Employees Permissions View'],
        ['id' => 55, 'name' => 'إدارة صلاحيات الموظفين', 'name_en' => 'Employees Permissions Manage'],
        ['id' => 56, 'name' => 'مشاهدة ماليات الموظفين', 'name_en' => 'Employees Financial View'],
        ['id' => 57, 'name' => 'دفع رواتب الموظفين', 'name_en' => 'Employees Salary Pay'],
        ['id' => 58, 'name' => 'مشاهدة نقاط الموظفين', 'name_en' => 'Employees Points View'],
        ['id' => 59, 'name' => 'إدارة نقاط الموظفين', 'name_en' => 'Employees Points Manage'],
        ['id' => 60, 'name' => 'مشاهدة حضور الموظفين', 'name_en' => 'Employees Attendance View'],
        ['id' => 61, 'name' => 'إدارة حضور الموظفين', 'name_en' => 'Employees Attendance Manage'],
        ['id' => 62, 'name' => 'مشاهدة سجلات الموظفين', 'name_en' => 'Employees Logs View'],
        ['id' => 63, 'name' => 'إدارة طلبات الموظفين', 'name_en' => 'Employees Orders Manage'],
        ['id' => 64, 'name' => 'إدارة بصمة الموظفين', 'name_en' => 'Employees Fingerprint Manage'],
        ['id' => 65, 'name' => 'إدارة قواعد مكافآت الموظفين', 'name_en' => 'Employees Rewards Rules Manage'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('employee_permissions')) {
            return;
        }

        foreach ($this->permissions as $permission) {
            if (DB::table('permissions')->where('name_en', $permission['name_en'])->exists()) {
                continue;
            }

            $row = [
                'name' => $permission['name'],
                'name_en' => $permission['name_en'],
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if (! DB::table('permissions')->where('id', $permission['id'])->exists()) {
                $row['id'] = $permission['id'];
            }

            DB::table('permissions')->insert($row);
        }

        $employeesSectionId = DB::table('permissions')
            ->where('name_en', 'Employees Section')
            ->value('id');

        if (! $employeesSectionId) {
            return;
        }

        $employeeIds = DB::table('employee_permissions')
            ->where('permission_id', $employeesSectionId)
            ->pluck('employee_id')
            ->filter()
            ->unique()
            ->values();

        $newPermissionIds = DB::table('permissions')
            ->whereIn('name_en', array_column($this->permissions, 'name_en'))
            ->pluck('id');

        foreach ($employeeIds as $employeeId) {
            foreach ($newPermissionIds as $permissionId) {
                $exists = DB::table('employee_permissions')
                    ->where('employee_id', $employeeId)
                    ->where('permission_id', $permissionId)
                    ->exists();

                if (! $exists) {
                    DB::table('employee_permissions')->insert([
                        'employee_id' => $employeeId,
                        'permission_id' => $permissionId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        DB::table('permissions')
            ->whereIn('name_en', array_column($this->permissions, 'name_en'))
            ->delete();
    }
};
