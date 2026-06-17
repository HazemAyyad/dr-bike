<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('instant_sales')) {
            return;
        }

        Schema::table('instant_sales', function (Blueprint $table) {
            if (! Schema::hasColumn('instant_sales', 'sales_order_id')) {
                $table->foreignId('sales_order_id')
                    ->nullable()
                    ->after('sales_daily_session_id')
                    ->constrained('sales_orders')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('instant_sales')) {
            return;
        }

        Schema::table('instant_sales', function (Blueprint $table) {
            if (Schema::hasColumn('instant_sales', 'sales_order_id')) {
                $table->dropConstrainedForeignId('sales_order_id');
            }
        });
    }
};
