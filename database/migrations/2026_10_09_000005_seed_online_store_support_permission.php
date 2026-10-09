<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const NAME = 'دعم المتجر الإلكتروني';

    private const NAME_EN = 'Online Store Support';

    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        DB::table('permissions')->updateOrInsert(
            ['name_en' => self::NAME_EN],
            ['name' => self::NAME, 'updated_at' => now(), 'created_at' => now()]
        );

        $technicalSupportId = DB::table('permissions')->where('name_en', 'Technical Support')->value('id');
        $onlineStoreSupportId = DB::table('permissions')->where('name_en', self::NAME_EN)->value('id');
        if (! $technicalSupportId || ! $onlineStoreSupportId || ! Schema::hasTable('employee_permissions')) {
            return;
        }

        DB::table('employee_permissions')
            ->where('permission_id', $technicalSupportId)
            ->orderBy('id')
            ->get()
            ->each(function ($assignment) use ($onlineStoreSupportId) {
                DB::table('employee_permissions')->updateOrInsert(
                    [
                        'employee_id' => $assignment->employee_id,
                        'permission_id' => $onlineStoreSupportId,
                    ],
                    ['created_at' => now(), 'updated_at' => now()]
                );
            });
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $permissionId = DB::table('permissions')->where('name_en', self::NAME_EN)->value('id');
        if ($permissionId && Schema::hasTable('employee_permissions')) {
            DB::table('employee_permissions')->where('permission_id', $permissionId)->delete();
        }
        DB::table('permissions')->where('name_en', self::NAME_EN)->delete();
    }
};
