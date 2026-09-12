<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employee_task_template_assignees')) {
            Schema::create('employee_task_template_assignees', function (Blueprint $table) {
                $table->id();
                $table->foreignId('template_id')
                    ->constrained('employee_task_templates')
                    ->cascadeOnDelete();
                $table->foreignId('employee_id')
                    ->constrained('employee_details')
                    ->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['template_id', 'employee_id'], 'employee_task_template_assignees_unique');
            });
        }

        if (! Schema::hasTable('employee_task_occurrence_assignees')) {
            Schema::create('employee_task_occurrence_assignees', function (Blueprint $table) {
                $table->id();
                $table->foreignId('occurrence_id')
                    ->constrained('employee_task_occurrences')
                    ->cascadeOnDelete();
                $table->foreignId('employee_id')
                    ->constrained('employee_details')
                    ->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['occurrence_id', 'employee_id'], 'employee_task_occurrence_assignees_unique');
            });
        }

        $now = now();

        DB::table('employee_task_templates')
            ->select(['id', 'employee_id'])
            ->orderBy('id')
            ->chunkById(200, function ($templates) use ($now) {
                foreach ($templates as $template) {
                    $employeeIds = collect();

                    if (Schema::hasTable('employee_task_assignees')) {
                        $legacyTaskIds = DB::table('employee_tasks')
                            ->where('template_id', $template->id)
                            ->pluck('id');

                        if ($legacyTaskIds->isNotEmpty()) {
                            $employeeIds = DB::table('employee_task_assignees')
                                ->whereIn('employee_task_id', $legacyTaskIds)
                                ->pluck('employee_id');
                        }
                    }

                    if ($employeeIds->isEmpty()) {
                        $employeeIds = collect([(int) $template->employee_id]);
                    }

                    foreach ($employeeIds->map(fn ($id) => (int) $id)->filter()->unique() as $employeeId) {
                        DB::table('employee_task_template_assignees')->insertOrIgnore([
                            'template_id' => $template->id,
                            'employee_id' => $employeeId,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                }
            });

        DB::table('employee_task_occurrences')
            ->select(['id', 'template_id', 'employee_id', 'legacy_task_id'])
            ->orderBy('id')
            ->chunkById(200, function ($occurrences) use ($now) {
                foreach ($occurrences as $occurrence) {
                    $employeeIds = collect();

                    if ($occurrence->legacy_task_id && Schema::hasTable('employee_task_assignees')) {
                        $employeeIds = DB::table('employee_task_assignees')
                            ->where('employee_task_id', $occurrence->legacy_task_id)
                            ->pluck('employee_id');
                    }

                    if ($employeeIds->isEmpty()) {
                        $employeeIds = DB::table('employee_task_template_assignees')
                            ->where('template_id', $occurrence->template_id)
                            ->pluck('employee_id');
                    }

                    if ($employeeIds->isEmpty()) {
                        $employeeIds = collect([(int) $occurrence->employee_id]);
                    }

                    foreach ($employeeIds->map(fn ($id) => (int) $id)->filter()->unique() as $employeeId) {
                        DB::table('employee_task_occurrence_assignees')->insertOrIgnore([
                            'occurrence_id' => $occurrence->id,
                            'employee_id' => $employeeId,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_task_occurrence_assignees');
        Schema::dropIfExists('employee_task_template_assignees');
    }
};
