<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sub_employee_tasks')
            && ! Schema::hasColumn('sub_employee_tasks', 'rejection_reason')) {
            Schema::table('sub_employee_tasks', function (Blueprint $table) {
                $table->text('rejection_reason')->nullable()->after('status');
            });
        }

        if (Schema::hasTable('employee_task_occurrence_subtasks')
            && ! Schema::hasColumn('employee_task_occurrence_subtasks', 'rejection_reason')) {
            Schema::table('employee_task_occurrence_subtasks', function (Blueprint $table) {
                $table->text('rejection_reason')->nullable()->after('status');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sub_employee_tasks')
            && Schema::hasColumn('sub_employee_tasks', 'rejection_reason')) {
            Schema::table('sub_employee_tasks', function (Blueprint $table) {
                $table->dropColumn('rejection_reason');
            });
        }

        if (Schema::hasTable('employee_task_occurrence_subtasks')
            && Schema::hasColumn('employee_task_occurrence_subtasks', 'rejection_reason')) {
            Schema::table('employee_task_occurrence_subtasks', function (Blueprint $table) {
                $table->dropColumn('rejection_reason');
            });
        }
    }
};
