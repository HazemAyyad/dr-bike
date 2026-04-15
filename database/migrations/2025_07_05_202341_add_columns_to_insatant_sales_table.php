<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('instant_sales', function (Blueprint $table) {
            if (! Schema::hasColumn('instant_sales', 'quantity')) {
                $table->integer('quantity')->default(0);
            }
            if (! Schema::hasColumn('instant_sales', 'discount')) {
                $table->float('discount')->default(0);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('insatant_sales', function (Blueprint $table) {
            $table->dropColumns(['quantity', 'discount']);
        });
    }
};
