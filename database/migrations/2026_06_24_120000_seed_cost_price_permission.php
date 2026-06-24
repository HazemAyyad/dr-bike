<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $permission = [
            'name' => 'سعر التكلفة',
            'name_en' => 'Cost Price',
        ];

        $exists = DB::table('permissions')->where('name_en', $permission['name_en'])->exists();
        if (! $exists) {
            DB::table('permissions')->insert([
                'name' => $permission['name'],
                'name_en' => $permission['name_en'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        DB::table('permissions')->where('name_en', 'Cost Price')->delete();
    }
};
