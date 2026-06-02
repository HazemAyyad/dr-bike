<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fingerprint_device_users')) {
            Schema::create('fingerprint_device_users', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('attendance_device_id');
                $table->string('device_user_id');
                $table->string('name')->nullable();
                $table->string('privilege')->nullable();
                $table->string('card')->nullable();
                $table->string('password')->nullable();
                $table->boolean('enabled')->nullable();
                $table->json('raw_payload')->nullable();
                $table->unsignedBigInteger('linked_employee_id')->nullable();
                $table->timestamp('last_synced_at')->nullable();
                $table->timestamps();

                $table->foreign('attendance_device_id')->references('id')->on('attendance_devices')->onDelete('cascade');
                $table->foreign('linked_employee_id')->references('id')->on('employee_details')->nullOnDelete();
                $table->unique(['attendance_device_id', 'device_user_id'], 'fdu_device_user_unique');
            });
        }

        // Short index name to satisfy MySQL identifier length limits.
        try {
            Schema::table('fingerprint_device_users', function (Blueprint $table) {
                $table->index(['attendance_device_id', 'linked_employee_id'], 'fdu_device_employee_idx');
            });
        } catch (\Throwable $e) {
            // Best-effort: index may already exist.
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fingerprint_device_users');
    }
};

