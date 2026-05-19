<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('employee_task_templates')) {
            return;
        }

        Schema::create('employee_task_templates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->foreign('employee_id')->references('id')->on('employee_details')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('points')->default(0);
            $table->string('priority')->default('medium');
            $table->boolean('is_forced_to_upload_img')->default(false);
            $table->boolean('not_shown_for_employee')->default(false);
            $table->json('admin_img')->nullable();
            $table->string('audio')->nullable();
            $table->string('recurrence_type')->default('noRepeat');
            $table->json('recurrence_config')->nullable();
            $table->time('time_window_start')->nullable();
            $table->time('time_window_end')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('employee_task_template_subtasks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('template_id');
            $table->foreign('template_id')->references('id')->on('employee_task_templates')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('requires_image')->default(false);
            $table->unsignedInteger('bonus_points')->default(0);
            $table->json('admin_img')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_task_template_subtasks');
        Schema::dropIfExists('employee_task_templates');
    }
};
