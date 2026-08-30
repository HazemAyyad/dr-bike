<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const OLD_PHONE_NUMBER_ID = '1318574464666434';
    private const PHONE_NUMBER_ID = '1239393272601652';
    private const WABA_ID = '1394703721997438';
    private const CATALOG_ID = '1307086997998889';
    private const DISPLAY_PHONE_NUMBER = '972569600809';

    public function up(): void
    {
        if (! Schema::hasTable('whatsapp_accounts')) {
            return;
        }

        $updated = DB::table('whatsapp_accounts')
            ->where(function ($query) {
                $query->where('phone_number_id', self::OLD_PHONE_NUMBER_ID)
                    ->orWhere('display_phone_number', self::DISPLAY_PHONE_NUMBER);
            })
            ->update([
                'name' => 'Doctor Bike',
                'display_phone_number' => self::DISPLAY_PHONE_NUMBER,
                'phone_number_id' => self::PHONE_NUMBER_ID,
                'waba_id' => self::WABA_ID,
                'catalog_id' => self::CATALOG_ID,
                'access_token_env_key' => 'WHATSAPP_ACCESS_TOKEN_LIVE',
                'is_active' => true,
                'is_verified' => true,
                'updated_at' => now(),
            ]);

        if ($updated === 0 && ! DB::table('whatsapp_accounts')->where('phone_number_id', self::PHONE_NUMBER_ID)->exists()) {
            DB::table('whatsapp_accounts')->insert([
                'name' => 'Doctor Bike',
                'display_phone_number' => self::DISPLAY_PHONE_NUMBER,
                'phone_number_id' => self::PHONE_NUMBER_ID,
                'waba_id' => self::WABA_ID,
                'catalog_id' => self::CATALOG_ID,
                'access_token_env_key' => 'WHATSAPP_ACCESS_TOKEN_LIVE',
                'is_active' => true,
                'is_verified' => true,
                'sort_order' => 20,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('whatsapp_accounts')) {
            return;
        }

        DB::table('whatsapp_accounts')
            ->where('phone_number_id', self::PHONE_NUMBER_ID)
            ->update([
                'name' => 'Doctor Bike Israel',
                'phone_number_id' => self::OLD_PHONE_NUMBER_ID,
                'waba_id' => '1021382140304311',
                'catalog_id' => '2145157066409174',
                'access_token_env_key' => 'WHATSAPP_ACCESS_TOKEN',
                'is_active' => false,
                'is_verified' => false,
                'updated_at' => now(),
            ]);
    }
};
