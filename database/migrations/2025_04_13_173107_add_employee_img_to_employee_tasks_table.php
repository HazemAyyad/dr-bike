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
        Schema::table('employee_tasks', function (Blueprint $table) {
            if (! Schema::hasColumn('employee_tasks', 'admin_img')) {
                $table->string('admin_img')->nullable();
            }
            if (Schema::hasColumn('employee_tasks', 'img')
                && ! Schema::hasColumn('employee_tasks', 'employee_img')) {
                $table->renameColumn('img', 'employee_img');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employee_tasks', function (Blueprint $table) {
            if (Schema::hasColumn('employee_tasks', 'employee_img')
                && ! Schema::hasColumn('employee_tasks', 'img')) {
                $table->renameColumn('employee_img', 'img');
            }
            if (Schema::hasColumn('employee_tasks', 'admin_img')) {
                $table->dropColumn('admin_img');
            }
        });
    }
};
