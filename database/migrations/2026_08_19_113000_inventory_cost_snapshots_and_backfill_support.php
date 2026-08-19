<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('instant_sales')) {
            Schema::table('instant_sales', function (Blueprint $table) {
                if (! Schema::hasColumn('instant_sales', 'inventory_cost_method')) {
                    $table->string('inventory_cost_method', 40)->nullable()->after('total_cost');
                }
                if (! Schema::hasColumn('instant_sales', 'inventory_unit_cost')) {
                    $table->decimal('inventory_unit_cost', 14, 6)->nullable()->after('inventory_cost_method');
                }
                if (! Schema::hasColumn('instant_sales', 'inventory_total_cost')) {
                    $table->decimal('inventory_total_cost', 14, 6)->nullable()->after('inventory_unit_cost');
                }
            });
        }

        if (Schema::hasTable('maintenance_products')) {
            Schema::table('maintenance_products', function (Blueprint $table) {
                if (! Schema::hasColumn('maintenance_products', 'inventory_cost_method')) {
                    $table->string('inventory_cost_method', 40)->nullable()->after('line_total');
                }
                if (! Schema::hasColumn('maintenance_products', 'inventory_unit_cost')) {
                    $table->decimal('inventory_unit_cost', 14, 6)->nullable()->after('inventory_cost_method');
                }
                if (! Schema::hasColumn('maintenance_products', 'inventory_total_cost')) {
                    $table->decimal('inventory_total_cost', 14, 6)->nullable()->after('inventory_unit_cost');
                }
            });
        }
    }

    public function down(): void
    {
        // Additive production migration. Cost snapshots are intentionally retained.
    }
};
