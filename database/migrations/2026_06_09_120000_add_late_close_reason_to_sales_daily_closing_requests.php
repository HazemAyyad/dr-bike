<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sales_daily_closing_requests')
            && ! Schema::hasColumn('sales_daily_closing_requests', 'late_close_reason')) {
            Schema::table('sales_daily_closing_requests', function (Blueprint $table) {
                $table->text('late_close_reason')->nullable()->after('cash_counts');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sales_daily_closing_requests')
            && Schema::hasColumn('sales_daily_closing_requests', 'late_close_reason')) {
            Schema::table('sales_daily_closing_requests', function (Blueprint $table) {
                $table->dropColumn('late_close_reason');
            });
        }
    }
};
