<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** MySQL identifier limit is 64 chars — use short FK names. */
    private const SUB_EMP_TASKS_COMPLETED_BY_FK = 'sub_emp_tasks_completed_by_fk';

    private const ET_OCC_SUBTASKS_COMPLETED_BY_FK = 'et_occ_sub_completed_by_fk';

    public function up(): void
    {
        if (Schema::hasTable('sub_employee_tasks')) {
            if (! Schema::hasColumn('sub_employee_tasks', 'status')) {
                Schema::table('sub_employee_tasks', function (Blueprint $table) {
                    $table->string('status')->default('pending');
                });
            }

            if (! Schema::hasColumn('sub_employee_tasks', 'completed_by_employee_id')) {
                Schema::table('sub_employee_tasks', function (Blueprint $table) {
                    $table->unsignedBigInteger('completed_by_employee_id')->nullable()->after('status');
                });
            }

            if (! $this->foreignKeyExists('sub_employee_tasks', self::SUB_EMP_TASKS_COMPLETED_BY_FK)) {
                Schema::table('sub_employee_tasks', function (Blueprint $table) {
                    $table->foreign('completed_by_employee_id', self::SUB_EMP_TASKS_COMPLETED_BY_FK)
                        ->references('id')
                        ->on('employee_details')
                        ->nullOnDelete();
                });
            }
        }

        if (Schema::hasTable('employee_task_occurrence_subtasks')) {
            if (! Schema::hasColumn('employee_task_occurrence_subtasks', 'completed_by_employee_id')) {
                Schema::table('employee_task_occurrence_subtasks', function (Blueprint $table) {
                    $table->unsignedBigInteger('completed_by_employee_id')->nullable()->after('status');
                });
            }

            if (! $this->foreignKeyExists('employee_task_occurrence_subtasks', self::ET_OCC_SUBTASKS_COMPLETED_BY_FK)) {
                Schema::table('employee_task_occurrence_subtasks', function (Blueprint $table) {
                    $table->foreign('completed_by_employee_id', self::ET_OCC_SUBTASKS_COMPLETED_BY_FK)
                        ->references('id')
                        ->on('employee_details')
                        ->nullOnDelete();
                });
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sub_employee_tasks') && Schema::hasColumn('sub_employee_tasks', 'completed_by_employee_id')) {
            Schema::table('sub_employee_tasks', function (Blueprint $table) {
                if ($this->foreignKeyExists('sub_employee_tasks', self::SUB_EMP_TASKS_COMPLETED_BY_FK)) {
                    $table->dropForeign(self::SUB_EMP_TASKS_COMPLETED_BY_FK);
                }
                $table->dropColumn('completed_by_employee_id');
            });
        }

        if (
            Schema::hasTable('employee_task_occurrence_subtasks')
            && Schema::hasColumn('employee_task_occurrence_subtasks', 'completed_by_employee_id')
        ) {
            Schema::table('employee_task_occurrence_subtasks', function (Blueprint $table) {
                if ($this->foreignKeyExists('employee_task_occurrence_subtasks', self::ET_OCC_SUBTASKS_COMPLETED_BY_FK)) {
                    $table->dropForeign(self::ET_OCC_SUBTASKS_COMPLETED_BY_FK);
                }
                $table->dropColumn('completed_by_employee_id');
            });
        }
    }

    private function foreignKeyExists(string $table, string $constraintName): bool
    {
        $connection = Schema::getConnection();
        $database = $connection->getDatabaseName();

        $row = $connection->selectOne(
            'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS '
            .'WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = ?',
            [$database, $table, $constraintName, 'FOREIGN KEY']
        );

        return $row !== null;
    }
};
