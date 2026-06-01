<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach ($this->tables() as $tableName) {
            if (Schema::hasTable($tableName) && ! Schema::hasColumn($tableName, 'proof_media_type')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->string('proof_media_type', 20)->default('none');
                });
            }
        }
    }

    public function down(): void
    {
        foreach ($this->tables() as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'proof_media_type')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->dropColumn('proof_media_type');
                });
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function tables(): array
    {
        return [
            'employee_tasks',
            'sub_employee_tasks',
            'employee_task_templates',
            'employee_task_template_subtasks',
            'employee_task_occurrences',
            'employee_task_occurrence_subtasks',
        ];
    }
};
