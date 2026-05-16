<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instant_sales', function (Blueprint $table) {
            if (! Schema::hasColumn('instant_sales', 'stock_restored')) {
                $table->boolean('stock_restored')->default(false)->after('cancelled_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('instant_sales', function (Blueprint $table) {
            if (Schema::hasColumn('instant_sales', 'stock_restored')) {
                $table->dropColumn('stock_restored');
            }
        });
    }
};
