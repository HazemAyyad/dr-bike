<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['employee_tasks', 'employee_task_templates', 'employee_task_occurrences'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->boolean('is_paused')->default(false)->index());
        }
    }

    public function down(): void
    {
        foreach (['employee_tasks', 'employee_task_templates', 'employee_task_occurrences'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('is_paused'));
        }
    }
};
