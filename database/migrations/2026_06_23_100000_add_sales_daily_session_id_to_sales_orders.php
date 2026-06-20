<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sales_orders')) {
            return;
        }

        Schema::table('sales_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('sales_orders', 'sales_daily_session_id')) {
                $table->unsignedBigInteger('sales_daily_session_id')
                    ->nullable()
                    ->after('financial_posted_at');
                $table->foreign('sales_daily_session_id')
                    ->references('id')
                    ->on('sales_daily_sessions')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('sales_orders')) {
            return;
        }

        Schema::table('sales_orders', function (Blueprint $table) {
            if (Schema::hasColumn('sales_orders', 'sales_daily_session_id')) {
                $table->dropForeign(['sales_daily_session_id']);
                $table->dropColumn('sales_daily_session_id');
            }
        });
    }
};
