<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('employee_attendance_adjustments')) {
            return;
        }

        Schema::create('employee_attendance_adjustments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_attendance_id')->nullable();
            $table->unsignedBigInteger('employee_id');
            $table->date('work_date');
            $table->json('before_values')->nullable();
            $table->json('after_values')->nullable();
            $table->unsignedBigInteger('edited_by')->nullable();
            $table->string('source', 32)->default('admin_edit');
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'work_date']);
            $table->foreign('employee_attendance_id')
                ->references('id')
                ->on('employee_attendances')
                ->nullOnDelete();
            $table->foreign('employee_id')
                ->references('id')
                ->on('employee_details')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_attendance_adjustments');
    }
};
