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
        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'department_id')) {
                $table->unsignedBigInteger('department_id')->nullable();
            }
            if (! Schema::hasColumn('products', 'department_id')) {
                $table->foreign('department_id')->references('id')->on('departments')->onDelete('cascade');
            }
            if (! Schema::hasColumn('products', 'sub_department_id')) {
                $table->unsignedBigInteger('sub_department_id')->nullable();
            }
            if (! Schema::hasColumn('products', 'sub_department_id')) {
                $table->foreign('sub_department_id')->references('id')->on('sub_departments')->onDelete('cascade');
            }

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            //
        });
    }
};
