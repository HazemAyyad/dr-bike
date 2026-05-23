<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sub_employee_tasks') && ! Schema::hasColumn('sub_employee_tasks', 'completed_by_employee_id')) {
            Schema::table('sub_employee_tasks', function (Blueprint $table) {
                $table->unsignedBigInteger('completed_by_employee_id')->nullable()->after('status');
                $table->foreign('completed_by_employee_id')
                    ->references('id')
                    ->on('employee_details')
                    ->nullOnDelete();
            });
        }

        if (
            Schema::hasTable('employee_task_occurrence_subtasks')
            && ! Schema::hasColumn('employee_task_occurrence_subtasks', 'completed_by_employee_id')
        ) {
            Schema::table('employee_task_occurrence_subtasks', function (Blueprint $table) {
                $table->unsignedBigInteger('completed_by_employee_id')->nullable()->after('status');
                $table->foreign('completed_by_employee_id')
                    ->references('id')
                    ->on('employee_details')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sub_employee_tasks') && Schema::hasColumn('sub_employee_tasks', 'completed_by_employee_id')) {
            Schema::table('sub_employee_tasks', function (Blueprint $table) {
                $table->dropForeign(['completed_by_employee_id']);
                $table->dropColumn('completed_by_employee_id');
            });
        }

        if (
            Schema::hasTable('employee_task_occurrence_subtasks')
            && Schema::hasColumn('employee_task_occurrence_subtasks', 'completed_by_employee_id')
        ) {
            Schema::table('employee_task_occurrence_subtasks', function (Blueprint $table) {
                $table->dropForeign(['completed_by_employee_id']);
                $table->dropColumn('completed_by_employee_id');
            });
        }
    }
};
