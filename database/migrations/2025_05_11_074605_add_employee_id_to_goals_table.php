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
        Schema::table('goals', function (Blueprint $table) {
            if (! Schema::hasColumn('goals', 'employee_id')) {
                $table->unsignedBigInteger('employee_id')->nullable();
            }
            if (! Schema::hasColumn('goals', 'employee_id')) {
                $table->foreign('employee_id')->references('id')->on('employee_details')->onDelete('cascade');
            }

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('goals', function (Blueprint $table) {
            //
        });
    }
};
