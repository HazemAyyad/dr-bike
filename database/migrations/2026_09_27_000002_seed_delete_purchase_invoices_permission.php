<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const NAME_EN = 'Delete Purchase Invoices';

    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $values = [
            'name' => 'حذف فواتير الشراء نهائياً',
            'updated_at' => now(),
        ];
        if (Schema::hasColumn('permissions', 'grant_policy')) {
            $values['grant_policy'] = 'admin_only';
        }

        DB::table('permissions')->updateOrInsert(
            ['name_en' => self::NAME_EN],
            array_merge($values, ['created_at' => now()]),
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $id = DB::table('permissions')->where('name_en', self::NAME_EN)->value('id');
        if ($id && Schema::hasTable('employee_permissions')) {
            DB::table('employee_permissions')->where('permission_id', $id)->delete();
        }
        DB::table('permissions')->where('name_en', self::NAME_EN)->delete();
    }
};
