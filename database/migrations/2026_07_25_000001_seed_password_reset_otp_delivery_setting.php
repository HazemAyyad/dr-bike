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

        $exists = DB::table('app_settings')
            ->where('key', 'password_reset_otp_delivery_method')
            ->exists();

        if (! $exists) {
            DB::table('app_settings')->insert([
                'key' => 'password_reset_otp_delivery_method',
                'value' => 'email',
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
            ->where('key', 'password_reset_otp_delivery_method')
            ->delete();
    }
};
