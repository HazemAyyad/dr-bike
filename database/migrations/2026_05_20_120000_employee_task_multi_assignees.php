<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employee_task_assignees')) {
            Schema::create('employee_task_assignees', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('employee_task_id');
                $table->unsignedBigInteger('employee_id');
                $table->timestamps();

                $table->unique(['employee_task_id', 'employee_id']);
                $table->foreign('employee_task_id')
                    ->references('id')
                    ->on('employee_tasks')
                    ->cascadeOnDelete();
                $table->foreign('employee_id')
                    ->references('id')
                    ->on('employee_details')
                    ->cascadeOnDelete();
            });
        }

        if (Schema::hasTable('employee_tasks') && ! Schema::hasColumn('employee_tasks', 'completed_by_employee_id')) {
            Schema::table('employee_tasks', function (Blueprint $table) {
                $table->unsignedBigInteger('completed_by_employee_id')->nullable()->after('employee_id');
                $table->foreign('completed_by_employee_id')
                    ->references('id')
                    ->on('employee_details')
                    ->nullOnDelete();
            });
        }

        if (Schema::hasTable('employee_task_occurrences') && ! Schema::hasColumn('employee_task_occurrences', 'completed_by_employee_id')) {
            Schema::table('employee_task_occurrences', function (Blueprint $table) {
                $table->unsignedBigInteger('completed_by_employee_id')->nullable()->after('employee_id');
                $table->foreign('completed_by_employee_id')
                    ->references('id')
                    ->on('employee_details')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('employee_tasks') && Schema::hasColumn('employee_tasks', 'completed_by_employee_id')) {
            Schema::table('employee_tasks', function (Blueprint $table) {
                $table->dropForeign(['completed_by_employee_id']);
                $table->dropColumn('completed_by_employee_id');
            });
        }

        if (Schema::hasTable('employee_task_occurrences') && Schema::hasColumn('employee_task_occurrences', 'completed_by_employee_id')) {
            Schema::table('employee_task_occurrences', function (Blueprint $table) {
                $table->dropForeign(['completed_by_employee_id']);
                $table->dropColumn('completed_by_employee_id');
            });
        }

        Schema::dropIfExists('employee_task_assignees');
    }
};
