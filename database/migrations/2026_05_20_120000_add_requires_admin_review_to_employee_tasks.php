<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tables = ['employee_tasks', 'employee_task_templates', 'employee_task_occurrences'];

        foreach ($tables as $name) {
            if (! Schema::hasTable($name) || Schema::hasColumn($name, 'requires_admin_review')) {
                continue;
            }

            Schema::table($name, function (Blueprint $table) {
                $table->boolean('requires_admin_review')->default(true);
            });
        }
    }

    public function down(): void
    {
        $tables = ['employee_tasks', 'employee_task_templates', 'employee_task_occurrences'];

        foreach ($tables as $name) {
            if (! Schema::hasTable($name) || ! Schema::hasColumn($name, 'requires_admin_review')) {
                continue;
            }

            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('requires_admin_review');
            });
        }
    }
};
