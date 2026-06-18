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
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('employee_attendance_id')->nullable();
            $table->date('work_date');
            $table->unsignedInteger('requested_minutes');
            $table->unsignedInteger('approved_minutes')->nullable();
            $table->string('status', 16)->default('pending');
            $table->string('checkout_source', 16)->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('admin_note')->nullable();
            $table->timestamps();

            $table->foreign('employee_id', 'ea_ot_req_employee_id_fk')
                ->references('id')->on('employee_details')->cascadeOnDelete();
            $table->foreign('employee_attendance_id', 'ea_ot_req_attendance_id_fk')
                ->references('id')->on('employee_attendances')->nullOnDelete();
            $table->foreign('reviewed_by', 'ea_ot_req_reviewed_by_fk')
                ->references('id')->on('users')->nullOnDelete();

            $table->index(['status', 'work_date'], 'ea_ot_req_status_date_idx');
            $table->index(['employee_id', 'work_date'], 'ea_ot_req_emp_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_attendance_overtime_requests');
    }
};
