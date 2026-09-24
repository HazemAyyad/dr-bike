<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<int, array{name: string, name_en: string}> */
    private array $permissions = [
        ['name' => 'مشاهدة المصاريف', 'name_en' => 'Financial Expenses View'],
        ['name' => 'إضافة المصاريف', 'name_en' => 'Financial Expenses Create'],
        ['name' => 'تعديل المصاريف', 'name_en' => 'Financial Expenses Edit'],
        ['name' => 'تقارير وتصدير المصاريف', 'name_en' => 'Financial Expenses Reports'],
        ['name' => 'مشاهدة الإتلاف', 'name_en' => 'Financial Destructions View'],
        ['name' => 'إدارة الإتلاف', 'name_en' => 'Financial Destructions Manage'],
        ['name' => 'مشاهدة الأصول', 'name_en' => 'Financial Assets View'],
        ['name' => 'إضافة وتعديل الأصول', 'name_en' => 'Financial Assets Manage'],
        ['name' => 'حذف الأصول', 'name_en' => 'Financial Assets Delete'],
        ['name' => 'تنفيذ إهلاك الأصول', 'name_en' => 'Financial Assets Depreciate'],
        ['name' => 'تقارير الأصول', 'name_en' => 'Financial Assets Reports'],
        ['name' => 'مشاهدة الأوراق الرسمية', 'name_en' => 'Financial Official Papers View'],
        ['name' => 'إدارة الأوراق الرسمية', 'name_en' => 'Financial Official Papers Manage'],
        ['name' => 'أرشفة وحذف الأوراق الرسمية', 'name_en' => 'Financial Official Papers Delete'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('employee_permissions')) {
            return;
        }

        $hasGrantPolicy = Schema::hasColumn('permissions', 'grant_policy');
        foreach ($this->permissions as $permission) {
            $values = [
                'name' => $permission['name'],
                'updated_at' => now(),
            ];
            if ($hasGrantPolicy) {
                $values['grant_policy'] = 'permissions_manage';
            }

            DB::table('permissions')->updateOrInsert(
                ['name_en' => $permission['name_en']],
                $values + ['created_at' => now()]
            );
        }

        $parentId = DB::table('permissions')
            ->where('name_en', 'Expenses and Financial Affairs')
            ->value('id');
        if (! $parentId) {
            return;
        }

        $employeeIds = DB::table('employee_permissions')
            ->where('permission_id', $parentId)
            ->pluck('employee_id')
            ->filter()
            ->unique();
        $permissionIds = DB::table('permissions')
            ->whereIn('name_en', array_column($this->permissions, 'name_en'))
            ->pluck('id');

        foreach ($employeeIds as $employeeId) {
            foreach ($permissionIds as $permissionId) {
                $assignment = [
                    'employee_id' => $employeeId,
                    'permission_id' => $permissionId,
                ];
                if (! DB::table('employee_permissions')->where($assignment)->exists()) {
                    DB::table('employee_permissions')->insert($assignment + [
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        // Intentionally non-destructive: assigned financial access is retained.
    }
};
