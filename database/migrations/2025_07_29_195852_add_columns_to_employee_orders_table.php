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
        Schema::table('employee_orders', function (Blueprint $table) {
            $table->integer('overtime_value')->nullable();
            $table->integer('loan_value')->nullable();
            $table->string('type')->nullable();

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employee_orders', function (Blueprint $table) {
            //
        });
    }
};
