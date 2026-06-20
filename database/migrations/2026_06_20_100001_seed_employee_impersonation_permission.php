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
            'name' => 'الدخول كموظف',
            'name_en' => 'Employee Impersonation',
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

        DB::table('permissions')->where('name_en', 'Employee Impersonation')->delete();
    }
};
