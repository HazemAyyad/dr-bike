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
            if (! Schema::hasColumn('sales_orders', 'shiply_quoted_delivery_fee')) {
                $table->decimal('shiply_quoted_delivery_fee', 14, 2)
                    ->nullable()
                    ->after('customer_delivery_fee');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('sales_orders')) {
            return;
        }

        Schema::table('sales_orders', function (Blueprint $table) {
            if (Schema::hasColumn('sales_orders', 'shiply_quoted_delivery_fee')) {
                $table->dropColumn('shiply_quoted_delivery_fee');
            }
        });
    }
};
