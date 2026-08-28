<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_daily_closing_requests', function (Blueprint $table) {
            $table->json('sales_orders_cash_counts')->nullable()->after('cash_counts');
        });
    }

    public function down(): void
    {
        Schema::table('sales_daily_closing_requests', function (Blueprint $table) {
            $table->dropColumn('sales_orders_cash_counts');
        });
    }
};
