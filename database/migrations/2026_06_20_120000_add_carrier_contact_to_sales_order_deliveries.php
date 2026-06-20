<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sales_order_deliveries')) {
            return;
        }

        Schema::table('sales_order_deliveries', function (Blueprint $table) {
            if (! Schema::hasColumn('sales_order_deliveries', 'carrier_contact_name')) {
                $table->string('carrier_contact_name', 255)->nullable()->after('tracking_number');
            }
            if (! Schema::hasColumn('sales_order_deliveries', 'carrier_contact_phone')) {
                $table->string('carrier_contact_phone', 50)->nullable()->after('carrier_contact_name');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('sales_order_deliveries')) {
            return;
        }

        Schema::table('sales_order_deliveries', function (Blueprint $table) {
            foreach (['carrier_contact_phone', 'carrier_contact_name'] as $col) {
                if (Schema::hasColumn('sales_order_deliveries', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
