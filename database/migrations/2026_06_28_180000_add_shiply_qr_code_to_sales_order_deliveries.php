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
            if (! Schema::hasColumn('sales_order_deliveries', 'shiply_qr_code')) {
                $table->string('shiply_qr_code', 255)->nullable()->after('shiply_parcel_code');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('sales_order_deliveries')) {
            return;
        }

        Schema::table('sales_order_deliveries', function (Blueprint $table) {
            if (Schema::hasColumn('sales_order_deliveries', 'shiply_qr_code')) {
                $table->dropColumn('shiply_qr_code');
            }
        });
    }
};
