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

        $now = now();
        $rows = [
            [
                'key' => 'sales_daily_variance_alert_threshold',
                'value' => (string) config('sales_daily.variance_alert_threshold', 50),
            ],
            [
                'key' => 'sales_daily_max_float_json',
                'value' => json_encode(
                    config('sales_daily.max_float', [
                        'شيكل' => 500,
                        'دولار' => 200,
                        'دينار' => 200,
                    ]),
                    JSON_UNESCAPED_UNICODE
                ),
            ],
        ];

        foreach ($rows as $row) {
            $exists = DB::table('app_settings')->where('key', $row['key'])->exists();
            if (! $exists) {
                DB::table('app_settings')->insert([
                    'key' => $row['key'],
                    'value' => $row['value'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('app_settings')) {
            return;
        }

        DB::table('app_settings')->whereIn('key', [
            'sales_daily_variance_alert_threshold',
            'sales_daily_max_float_json',
        ])->delete();
    }
};
