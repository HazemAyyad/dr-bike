<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('employee_device_mappings')) {
            return;
        }

        Schema::create('employee_device_mappings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('attendance_device_id')->nullable();
            $table->string('device_user_id');
            $table->string('device_user_name')->nullable();
            $table->string('device_card_number')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->foreign('employee_id')->references('id')->on('employee_details')->onDelete('cascade');
            $table->foreign('attendance_device_id')->references('id')->on('attendance_devices')->nullOnDelete();

            $table->unique(['attendance_device_id', 'device_user_id'], 'udm_device_user_unique');
            $table->unique(['employee_id', 'attendance_device_id'], 'udm_employee_device_unique');
            $table->index(['employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_device_mappings');
    }
};

