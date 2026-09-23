<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('accounting_accounts')) {
            return;
        }

        $existing = DB::table('accounting_accounts')
            ->where('system_key', 'service_revenue')
            ->first();

        if ($existing) {
            DB::table('accounting_accounts')
                ->where('id', $existing->id)
                ->update([
                    'name_ar' => 'إيرادات الخدمات',
                    'name_en' => 'Service Revenue',
                    'type' => 'revenue',
                    'normal_balance' => 'credit',
                    'is_control' => true,
                    'is_active' => true,
                    'updated_at' => now(),
                ]);

            return;
        }

        $code = '4150';
        if (DB::table('accounting_accounts')->where('code', $code)->exists()) {
            $code = collect(range(4151, 4199))
                ->map(fn (int $candidate) => (string) $candidate)
                ->first(fn (string $candidate) => ! DB::table('accounting_accounts')->where('code', $candidate)->exists());
        }

        if (! $code) {
            throw new RuntimeException('No available account code between 4150 and 4199 for service_revenue.');
        }

        DB::table('accounting_accounts')->insert([
            'code' => $code,
            'system_key' => 'service_revenue',
            'name_ar' => 'إيرادات الخدمات',
            'name_en' => 'Service Revenue',
            'type' => 'revenue',
            'normal_balance' => 'credit',
            'parent_id' => null,
            'is_control' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Intentionally non-destructive. The row may have existed before this
        // migration or may already be referenced by production journals.
    }
};
