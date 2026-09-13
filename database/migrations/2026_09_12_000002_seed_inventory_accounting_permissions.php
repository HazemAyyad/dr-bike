<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = [
        ['name' => 'عرض تكلفة المخزون', 'name_en' => 'View Inventory Cost'],
        ['name' => 'تسوية كمية المخزون', 'name_en' => 'Adjust Stock'],
        ['name' => 'إعادة تقييم تكلفة المخزون', 'name_en' => 'Adjust Inventory Cost'],
        ['name' => 'إدارة المشتريات', 'name_en' => 'Manage Purchases'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        foreach (self::PERMISSIONS as $permission) {
            $values = [
                'name' => $permission['name'],
                'updated_at' => now(),
            ];
            if (Schema::hasColumn('permissions', 'grant_policy')) {
                $values['grant_policy'] = 'permissions_manage';
            }

            DB::table('permissions')->updateOrInsert(
                ['name_en' => $permission['name_en']],
                array_merge($values, ['created_at' => now()])
            );
        }

        if (! Schema::hasTable('employee_permissions')) {
            return;
        }

        $this->copyLegacyPermission('Cost Price', 'View Inventory Cost');
        $this->copyLegacyPermission('Cost Price', 'Adjust Inventory Cost');
        $this->copyLegacyPermission('Stock', 'Adjust Stock');
        $this->copyLegacyPermission('Purchasing Section', 'Manage Purchases');
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $ids = DB::table('permissions')
            ->whereIn('name_en', array_column(self::PERMISSIONS, 'name_en'))
            ->pluck('id');
        if (Schema::hasTable('employee_permissions') && $ids->isNotEmpty()) {
            DB::table('employee_permissions')->whereIn('permission_id', $ids)->delete();
        }
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }

    private function copyLegacyPermission(string $legacyName, string $newName): void
    {
        $legacyId = DB::table('permissions')->where('name_en', $legacyName)->value('id');
        $newId = DB::table('permissions')->where('name_en', $newName)->value('id');
        if (! $legacyId || ! $newId) {
            return;
        }

        DB::table('employee_permissions')
            ->where('permission_id', $legacyId)
            ->pluck('employee_id')
            ->each(function ($employeeId) use ($newId) {
                DB::table('employee_permissions')->updateOrInsert([
                    'employee_id' => $employeeId,
                    'permission_id' => $newId,
                ]);
            });
    }
};
