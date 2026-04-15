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
        Schema::table('sub_employee_tasks', function (Blueprint $table) {

            if (! Schema::hasColumn('sub_employee_tasks', 'admin_img')) {
                $table->string('admin_img')->nullable();
            }
            if (! Schema::hasColumn('sub_employee_tasks', 'is_forced_to_upload_img')) {
                $table->boolean('is_forced_to_upload_img')->default(0);
            }
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
