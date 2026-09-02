<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sales_orders') && ! Schema::hasColumn('sales_orders', 'reserves_stock')) {
            Schema::table('sales_orders', function (Blueprint $table) {
                $table->boolean('reserves_stock')->default(true)->after('stock_deducted_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sales_orders') && Schema::hasColumn('sales_orders', 'reserves_stock')) {
            Schema::table('sales_orders', function (Blueprint $table) {
                $table->dropColumn('reserves_stock');
            });
        }
    }
};
