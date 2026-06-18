<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('delivery_companies')) {
            return;
        }

        $now = now();
        $companies = [
            ['name' => 'مكتب توصيل', 'code' => 'office', 'sort_order' => 2],
            ['name' => 'تكسي', 'code' => 'taxi', 'sort_order' => 3],
        ];

        foreach ($companies as $company) {
            $exists = DB::table('delivery_companies')
                ->where('code', $company['code'])
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('delivery_companies')->insert([
                'name' => $company['name'],
                'code' => $company['code'],
                'is_active' => true,
                'sort_order' => $company['sort_order'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('delivery_companies')) {
            return;
        }

        DB::table('delivery_companies')
            ->whereIn('code', ['office', 'taxi'])
            ->delete();
    }
};
