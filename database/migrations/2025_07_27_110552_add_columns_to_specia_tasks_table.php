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
        Schema::table('special_tasks', function (Blueprint $table) {
            if (! Schema::hasColumn('special_tasks', 'admin_img')) {
                $table->string('admin_img')->nullable();
            }
            if (! Schema::hasColumn('special_tasks', 'force_employee_to_add_img_for_sub_task')) {
                $table->boolean('force_employee_to_add_img_for_sub_task')->default(0);
            }
            if (! Schema::hasColumn('special_tasks', 'force_employee_to_add_img')) {
                $table->boolean('force_employee_to_add_img')->default(0);
            }
            if (! Schema::hasColumn('special_tasks', 'audio')) {
                $table->string('audio')->nullable();
            }

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('specia_tasks', function (Blueprint $table) {
            //
        });
    }
};
