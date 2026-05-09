<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('employee_attendance_scans')) {
            return;
        }

        Schema::create('employee_attendance_scans', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->date('work_date');
            $table->dateTime('scanned_at');
            $table->string('direction', 8);
            $table->timestamps();

            $table->foreign('employee_id')->references('id')->on('employee_details')->onDelete('cascade');
            $table->index(['employee_id', 'work_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_attendance_scans');
    }
};
