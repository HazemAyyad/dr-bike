<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('employee_task_occurrences')) {
            return;
        }

        Schema::create('employee_task_occurrences', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('template_id')->nullable();
            $table->foreign('template_id')->references('id')->on('employee_task_templates')->nullOnDelete();
            $table->unsignedBigInteger('employee_id');
            $table->foreign('employee_id')->references('id')->on('employee_details')->cascadeOnDelete();
            $table->unsignedBigInteger('legacy_task_id')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('points')->default(0);
            $table->string('priority')->default('medium');
            $table->string('status')->default('pending');
            $table->boolean('is_canceled')->default(false);
            $table->dateTime('start_time');
            $table->dateTime('end_time');
            $table->date('scheduled_date')->nullable();
            $table->json('employee_img')->nullable();
            $table->json('admin_img')->nullable();
            $table->string('audio')->nullable();
            $table->boolean('is_forced_to_upload_img')->default(false);
            $table->boolean('not_shown_for_employee')->default(false);
            $table->text('rejection_notes')->nullable();
            $table->text('employee_notes')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'status', 'scheduled_date'], 'eto_emp_status_date_idx');
            $table->index(['template_id', 'scheduled_date'], 'eto_tpl_date_idx');
        });

        Schema::create('employee_task_occurrence_subtasks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('occurrence_id');
            $table->foreign('occurrence_id')->references('id')->on('employee_task_occurrences')->cascadeOnDelete();
            $table->unsignedBigInteger('template_subtask_id')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('requires_image')->default(false);
            $table->unsignedInteger('bonus_points')->default(0);
            $table->string('status')->default('pending');
            $table->json('admin_img')->nullable();
            $table->json('employee_img')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_task_occurrence_subtasks');
        Schema::dropIfExists('employee_task_occurrences');
    }
};
