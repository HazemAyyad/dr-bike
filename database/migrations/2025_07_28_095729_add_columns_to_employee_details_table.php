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
        Schema::table('employee_details', function (Blueprint $table) {
            if (! Schema::hasColumn('employee_details', 'work_time')) {
                $table->string('work_time')->nullable();
            }
            if (! Schema::hasColumn('employee_details', 'employee_img')) {
                $table->string('employee_img')->nullable();
            }
            if (! Schema::hasColumn('employee_details', 'document_img')) {
                $table->string('document_img')->nullable();
            }

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employee_details', function (Blueprint $table) {
            //
        });
    }
};
