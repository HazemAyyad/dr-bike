<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('asset_logs')) {
            return;
        }

        Schema::table('asset_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('asset_logs', 'depreciation_period')) {
                $table->string('depreciation_period', 7)->nullable()->after('type');
            }
            if (! Schema::hasColumn('asset_logs', 'value_before')) {
                $table->decimal('value_before', 15, 2)->nullable()->after('total');
            }
            if (! Schema::hasColumn('asset_logs', 'depreciation_amount')) {
                $table->decimal('depreciation_amount', 15, 2)->nullable()->after('value_before');
            }
            if (! Schema::hasColumn('asset_logs', 'processed_by_user_id')) {
                $table->unsignedBigInteger('processed_by_user_id')->nullable()->after('depreciation_period');
                $table->foreign('processed_by_user_id')->references('id')->on('users')->nullOnDelete();
            }
        });

        Schema::table('asset_logs', function (Blueprint $table) {
            $table->unique(['asset_id', 'depreciation_period'], 'asset_logs_asset_period_unique');
        });
    }

    public function down(): void
    {
        // Deliberately non-destructive: depreciation history must never be removed.
    }
};
