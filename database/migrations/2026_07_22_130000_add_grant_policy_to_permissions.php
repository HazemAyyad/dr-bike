<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        Schema::table('permissions', function (Blueprint $table) {
            if (! Schema::hasColumn('permissions', 'grant_policy')) {
                $table->string('grant_policy', 32)
                    ->default('permissions_manage')
                    ->after('name_en');
            }
        });

        DB::table('permissions')
            ->whereIn('name_en', [
                'Debts',
                'Boxes Section',
                'Special Tasks',
                'Checks',
            ])
            ->update([
                'grant_policy' => 'admin_only',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions') ||
            ! Schema::hasColumn('permissions', 'grant_policy')) {
            return;
        }

        Schema::table('permissions', function (Blueprint $table) {
            $table->dropColumn('grant_policy');
        });
    }
};
