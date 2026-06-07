<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employee_attendance_scans')) {
            return;
        }

        Schema::table('employee_attendance_scans', function (Blueprint $table) {
            if (! Schema::hasColumn('employee_attendance_scans', 'source')) {
                $table->string('source', 16)->nullable()->after('direction');
            }
            if (! Schema::hasColumn('employee_attendance_scans', 'server_received_at')) {
                $table->dateTime('server_received_at')->nullable()->after('source');
            }
            if (! Schema::hasColumn('employee_attendance_scans', 'fingerprint_raw_log_id')) {
                $table->unsignedBigInteger('fingerprint_raw_log_id')->nullable()->after('server_received_at');
                $table->index(['fingerprint_raw_log_id']);
            }
        });

        if (
            Schema::hasTable('fingerprint_raw_logs')
            && Schema::hasColumn('employee_attendance_scans', 'fingerprint_raw_log_id')
        ) {
            Schema::table('employee_attendance_scans', function (Blueprint $table) {
                $table->foreign('fingerprint_raw_log_id')
                    ->references('id')
                    ->on('fingerprint_raw_logs')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('employee_attendance_scans')) {
            return;
        }

        Schema::table('employee_attendance_scans', function (Blueprint $table) {
            if (Schema::hasColumn('employee_attendance_scans', 'fingerprint_raw_log_id')) {
                try {
                    $table->dropForeign(['fingerprint_raw_log_id']);
                } catch (\Throwable $e) {
                    // ignore if FK was never created
                }
            }
            foreach (['fingerprint_raw_log_id', 'server_received_at', 'source'] as $col) {
                if (Schema::hasColumn('employee_attendance_scans', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
