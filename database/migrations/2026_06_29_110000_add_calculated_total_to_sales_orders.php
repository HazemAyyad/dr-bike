<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('sales_orders', 'calculated_total')) {
                $table->decimal('calculated_total', 14, 2)
                    ->nullable()
                    ->after('discount');
            }
        });

        DB::table('sales_orders')
            ->whereNull('calculated_total')
            ->update(['calculated_total' => DB::raw('total')]);
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            if (Schema::hasColumn('sales_orders', 'calculated_total')) {
                $table->dropColumn('calculated_total');
            }
        });
    }
};
