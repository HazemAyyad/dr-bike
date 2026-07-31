<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSION_ID = 67;
    private const NAME = 'تعديل المنتجات';
    private const NAME_EN = 'Product Quick Edit';

    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        if (DB::table('permissions')->where('name_en', self::NAME_EN)->exists()) {
            return;
        }

        $row = [
            'name' => self::NAME,
            'name_en' => self::NAME_EN,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (Schema::hasColumn('permissions', 'grant_policy')) {
            $row['grant_policy'] = 'permissions_manage';
        }

        if (! DB::table('permissions')->where('id', self::PERMISSION_ID)->exists()) {
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
