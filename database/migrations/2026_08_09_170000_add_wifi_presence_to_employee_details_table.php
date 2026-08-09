<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_details', function (Blueprint $table) {
            if (! Schema::hasColumn('employee_details', 'wifi_ssid')) {
                $table->string('wifi_ssid')->nullable()->after('total_work_hours');
            }
            if (! Schema::hasColumn('employee_details', 'wifi_connected')) {
                $table->boolean('wifi_connected')->default(false)->after('wifi_ssid');
            }
            if (! Schema::hasColumn('employee_details', 'wifi_status_updated_at')) {
                $table->timestamp('wifi_status_updated_at')->nullable()->after('wifi_connected');
            }
        });
    }

    public function down(): void
    {
        Schema::table('employee_details', function (Blueprint $table) {
            if (Schema::hasColumn('employee_details', 'wifi_status_updated_at')) {
                $table->dropColumn('wifi_status_updated_at');
            }
            if (Schema::hasColumn('employee_details', 'wifi_connected')) {
                $table->dropColumn('wifi_connected');
            }
            if (Schema::hasColumn('employee_details', 'wifi_ssid')) {
                $table->dropColumn('wifi_ssid');
            }
        });
    }
};
