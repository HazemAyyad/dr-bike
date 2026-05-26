<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employee_reminders')) {
            Schema::create('employee_reminders', function (Blueprint $table) {
                $table->id();
                $table->foreignId('employee_id')->constrained('employee_details')->cascadeOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('title');
                $table->text('description')->nullable();
                $table->dateTime('scheduled_at');
                $table->string('repeat_type', 20)->default('once')->index();
                $table->boolean('is_active')->default(true)->index();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['employee_id', 'is_active', 'scheduled_at']);
            });
        }

        if (! Schema::hasTable('employee_reminder_occurrences')) {
            Schema::create('employee_reminder_occurrences', function (Blueprint $table) {
                $table->id();
                $table->foreignId('reminder_id')->constrained('employee_reminders')->cascadeOnDelete();
                $table->foreignId('employee_id')->constrained('employee_details')->cascadeOnDelete();
                $table->dateTime('scheduled_at');
                $table->dateTime('notified_at')->nullable();
                $table->dateTime('completed_at')->nullable();
                $table->dateTime('snoozed_until')->nullable();
                $table->string('status', 20)->default('pending')->index();
                $table->timestamps();

                $table->unique(['reminder_id', 'scheduled_at'], 'ero_reminder_scheduled_unique');
                $table->index(['employee_id', 'status', 'scheduled_at'], 'ero_emp_status_scheduled_idx');
                $table->index(['status', 'scheduled_at', 'notified_at'], 'ero_due_notifications_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_reminder_occurrences');
        Schema::dropIfExists('employee_reminders');
    }
};
