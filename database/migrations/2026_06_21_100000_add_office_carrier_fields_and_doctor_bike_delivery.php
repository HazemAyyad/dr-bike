<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sales_order_deliveries')) {
            Schema::table('sales_order_deliveries', function (Blueprint $table) {
                if (! Schema::hasColumn('sales_order_deliveries', 'carrier_office_name')) {
                    $table->string('carrier_office_name', 255)->nullable()->after('carrier_contact_phone');
                }
                if (! Schema::hasColumn('sales_order_deliveries', 'carrier_vehicle_number')) {
                    $table->string('carrier_vehicle_number', 50)->nullable()->after('carrier_office_name');
                }
            });
        }

        if (! Schema::hasTable('delivery_companies')) {
            return;
        }

        $exists = DB::table('delivery_companies')
            ->where('code', 'doctor_bike')
            ->exists();

        if ($exists) {
            return;
        }

        $now = now();
        DB::table('delivery_companies')->insert([
            'name' => 'دكتور بايك ديليفري',
            'code' => 'doctor_bike',
            'is_active' => true,
            'sort_order' => 4,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        if (Schema::hasTable('sales_order_deliveries')) {
            Schema::table('sales_order_deliveries', function (Blueprint $table) {
                foreach (['carrier_vehicle_number', 'carrier_office_name'] as $col) {
                    if (Schema::hasColumn('sales_order_deliveries', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }

        if (Schema::hasTable('delivery_companies')) {
            DB::table('delivery_companies')->where('code', 'doctor_bike')->delete();
        }
    }
};
