<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const NAME = 'دعم المتجر';

    private const NAME_EN = 'Online Store Support';

    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $values = [
            'name' => self::NAME,
            'updated_at' => now(),
        ];
        if (Schema::hasColumn('permissions', 'grant_policy')) {
            $values['grant_policy'] = 'permissions_manage';
        }

        DB::table('permissions')->updateOrInsert(
            ['name_en' => self::NAME_EN],
            $values + ['created_at' => now()]
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        DB::table('permissions')
            ->where('name_en', self::NAME_EN)
            ->update([
                'name' => 'دعم المتجر الإلكتروني',
                'updated_at' => now(),
            ]);
    }
};
