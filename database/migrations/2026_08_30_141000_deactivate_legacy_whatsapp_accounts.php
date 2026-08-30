<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ACTIVE_PHONE_NUMBER_ID = '1239393272601652';

    public function up(): void
    {
        if (! Schema::hasTable('whatsapp_accounts')) {
            return;
        }

        DB::table('whatsapp_accounts')
            ->where('phone_number_id', '!=', self::ACTIVE_PHONE_NUMBER_ID)
            ->update([
                'is_active' => false,
                'updated_at' => now(),
            ]);

        DB::table('whatsapp_accounts')
            ->where('phone_number_id', self::ACTIVE_PHONE_NUMBER_ID)
            ->update([
                'is_active' => true,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('whatsapp_accounts')) {
            return;
        }

        DB::table('whatsapp_accounts')
            ->where('phone_number_id', '1225704637288803')
            ->update([
                'is_active' => true,
                'updated_at' => now(),
            ]);
    }
};
