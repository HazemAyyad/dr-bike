<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('product_assembly_recipes') && ! Schema::hasColumn('product_assembly_recipes', 'additional_cost')) {
            Schema::table('product_assembly_recipes', function (Blueprint $table) {
                $table->decimal('additional_cost', 12, 2)->default(0)->after('unit_cost');
            });
        }

        if (Schema::hasTable('product_assembly_operations') && ! Schema::hasColumn('product_assembly_operations', 'additional_cost')) {
            Schema::table('product_assembly_operations', function (Blueprint $table) {
                $table->decimal('additional_cost', 12, 2)->default(0)->after('total_cost');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('product_assembly_operations') && Schema::hasColumn('product_assembly_operations', 'additional_cost')) {
            Schema::table('product_assembly_operations', function (Blueprint $table) {
                $table->dropColumn('additional_cost');
            });
        }

        if (Schema::hasTable('product_assembly_recipes') && Schema::hasColumn('product_assembly_recipes', 'additional_cost')) {
            Schema::table('product_assembly_recipes', function (Blueprint $table) {
                $table->dropColumn('additional_cost');
            });
        }
    }
};
