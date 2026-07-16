<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const NAME = 'الدعم الفني';

    private const NAME_EN = 'Technical Support';

    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        if (DB::table('permissions')->where('name_en', self::NAME_EN)->exists()) {
            return;
        }

        DB::table('permissions')->insert([
            'name' => self::NAME,
            'name_en' => self::NAME_EN,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        DB::table('permissions')->where('name_en', self::NAME_EN)->delete();
    }
};
