<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employee_attendances')) {
            return;
        }

        Schema::table('employee_attendances', function (Blueprint $table) {
            if (! Schema::hasColumn('employee_attendances', 'source')) {
                $table->string('source', 32)->default('qr')->after('worked_minutes');
                $table->index(['source']);
            }
            if (! Schema::hasColumn('employee_attendances', 'attendance_device_id')) {
                $table->unsignedBigInteger('attendance_device_id')->nullable()->after('source');
                $table->index(['attendance_device_id']);
            }
            if (! Schema::hasColumn('employee_attendances', 'device_user_id')) {
                $table->string('device_user_id')->nullable()->after('attendance_device_id');
            }
            if (! Schema::hasColumn('employee_attendances', 'fingerprint_raw_log_id')) {
                $table->unsignedBigInteger('fingerprint_raw_log_id')->nullable()->after('device_user_id');
                $table->index(['fingerprint_raw_log_id']);
            }
        });

        Schema::table('employee_attendances', function (Blueprint $table) {
            if (Schema::hasColumn('employee_attendances', 'attendance_device_id')) {
                $table->foreign('attendance_device_id')
                    ->references('id')
                    ->on('attendance_devices')
                    ->nullOnDelete();
            }
            if (Schema::hasColumn('employee_attendances', 'fingerprint_raw_log_id')) {
                $table->foreign('fingerprint_raw_log_id')
                    ->references('id')
                    ->on('fingerprint_raw_logs')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('employee_attendances')) {
            return;
        }

        Schema::table('employee_attendances', function (Blueprint $table) {
            // Drop FKs first if they exist.
            try {
                $table->dropForeign(['attendance_device_id']);
            } catch (\Throwable $e) {
            }
            try {
                $table->dropForeign(['fingerprint_raw_log_id']);
            } catch (\Throwable $e) {
            }

            if (Schema::hasColumn('employee_attendances', 'fingerprint_raw_log_id')) {
                $table->dropColumn('fingerprint_raw_log_id');
            }
            if (Schema::hasColumn('employee_attendances', 'device_user_id')) {
                $table->dropColumn('device_user_id');
            }
            if (Schema::hasColumn('employee_attendances', 'attendance_device_id')) {
                $table->dropColumn('attendance_device_id');
            }
            if (Schema::hasColumn('employee_attendances', 'source')) {
                $table->dropColumn('source');
            }
        });
    }
};

