<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sales_daily_sessions') || Schema::hasColumn('sales_daily_sessions', 'session_type')) {
            return;
        }

        Schema::table('sales_daily_sessions', function (Blueprint $table) {
            // Existing sessions were shared and historically represent the instant-sales drawer.
            $table->string('session_type', 24)
                ->default('instant_sales')
                ->after('employee_id');
            $table->index(['session_type', 'status'], 'sds_type_status_idx');
            $table->index(['session_type', 'business_date'], 'sds_type_date_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('sales_daily_sessions') || ! Schema::hasColumn('sales_daily_sessions', 'session_type')) {
            return;
        }

        Schema::table('sales_daily_sessions', function (Blueprint $table) {
            $table->dropIndex('sds_type_status_idx');
            $table->dropIndex('sds_type_date_idx');
            $table->dropColumn('session_type');
        });
    }
};
