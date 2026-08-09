<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('app_settings')) {
            return;
        }

        $key = 'employee_allowed_wifi_ssids';
        if (! DB::table('app_settings')->where('key', $key)->exists()) {
            DB::table('app_settings')->insert([
                'key' => $key,
                'value' => implode(',', config('employee_wifi.allowed_ssids', [])),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('app_settings')) {
            return;
        }

        DB::table('app_settings')
            ->where('key', 'employee_allowed_wifi_ssids')
            ->delete();
    }
};
