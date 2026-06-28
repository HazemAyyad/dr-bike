<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('sales_orders', 'price_includes_delivery')) {
                $table->boolean('price_includes_delivery')
                    ->default(false)
                    ->after('customer_delivery_fee');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            if (Schema::hasColumn('sales_orders', 'price_includes_delivery')) {
                $table->dropColumn('price_includes_delivery');
            }
        });
    }
};
