<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_attendance_overtime_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employee_details')->cascadeOnDelete();
            $table->foreignId('employee_attendance_id')->nullable()->constrained('employee_attendances')->nullOnDelete();
            $table->date('work_date');
            $table->unsignedInteger('requested_minutes');
            $table->unsignedInteger('approved_minutes')->nullable();
            $table->string('status', 16)->default('pending');
            $table->string('checkout_source', 16)->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('admin_note')->nullable();
            $table->timestamps();

            $table->index(['status', 'work_date']);
            $table->index(['employee_id', 'work_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_attendance_overtime_requests');
    }
};
