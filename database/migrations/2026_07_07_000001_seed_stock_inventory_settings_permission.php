<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** رقم ثابت للصلاحية ليطابق ما هو معرّف في تطبيق الفلاتر. */
    private const PERMISSION_ID = 47;

    private const NAME = 'إعدادات المخزون';

    private const NAME_EN = 'Stock Inventory Settings';

    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $exists = DB::table('permissions')->where('name_en', self::NAME_EN)->exists();
        if ($exists) {
            return;
        }

        $idTaken = DB::table('permissions')->where('id', self::PERMISSION_ID)->exists();

        $row = [
            'name' => self::NAME,
            'name_en' => self::NAME_EN,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (! $idTaken) {
            $row['id'] = self::PERMISSION_ID;
        }

        DB::table('permissions')->insert($row);
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        DB::table('permissions')->where('name_en', self::NAME_EN)->delete();
    }
};
