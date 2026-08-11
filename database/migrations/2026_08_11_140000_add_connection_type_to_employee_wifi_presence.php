<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_details', function (Blueprint $table) {
            if (! Schema::hasColumn('employee_details', 'wifi_connection_type')) {
                $table->string('wifi_connection_type', 30)
                    ->nullable()
                    ->after('network_connected');
            }
        });

        Schema::table('employee_wifi_presence_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('employee_wifi_presence_logs', 'connection_type')) {
                $table->string('connection_type', 30)
                    ->nullable()
                    ->after('network_connected');
                $table->index(['employee_detail_id', 'connection_type', 'started_at'], 'emp_wifi_logs_employee_type_started_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('employee_wifi_presence_logs', function (Blueprint $table) {
            if (Schema::hasColumn('employee_wifi_presence_logs', 'connection_type')) {
                $table->dropIndex('emp_wifi_logs_employee_type_started_idx');
                $table->dropColumn('connection_type');
            }
        });

        Schema::table('employee_details', function (Blueprint $table) {
            if (Schema::hasColumn('employee_details', 'wifi_connection_type')) {
                $table->dropColumn('wifi_connection_type');
            }
        });
    }
};
