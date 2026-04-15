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
            if (! Schema::hasColumn('employee_orders', 'overtime_value')) {
                $table->integer('overtime_value')->nullable();
            }
            if (! Schema::hasColumn('employee_orders', 'loan_value')) {
                $table->integer('loan_value')->nullable();
            }
            if (! Schema::hasColumn('employee_orders', 'type')) {
                $table->string('type')->nullable();
            }

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
