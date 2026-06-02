<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fingerprint_raw_logs')) {
            return;
        }

        Schema::create('fingerprint_raw_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('attendance_device_id');
            $table->string('device_user_id');
            $table->string('device_log_uid')->nullable();
            $table->dateTime('scan_time');
            $table->string('verify_type')->nullable();
            $table->string('status')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->enum('processing_status', ['pending', 'processed', 'ignored', 'error'])->default('pending');
            $table->text('processing_error')->nullable();
            $table->timestamps();

            $table->foreign('attendance_device_id')->references('id')->on('attendance_devices')->onDelete('cascade');
            $table->unique(['attendance_device_id', 'device_user_id', 'scan_time'], 'frl_device_user_time_unique');
            $table->index(['attendance_device_id', 'scan_time']);
            $table->index(['processing_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fingerprint_raw_logs');
    }
};

