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

        DB::table('app_settings')->updateOrInsert(
            ['key' => 'admin_pending_closing_prompt_enabled'],
            ['value' => '1', 'updated_at' => now(), 'created_at' => now()]
        );
    }

    public function down(): void
    {
        if (Schema::hasTable('app_settings')) {
            DB::table('app_settings')
                ->where('key', 'admin_pending_closing_prompt_enabled')
                ->delete();
        }
    }
};
