<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employee_details')) {
            return;
        }

        Schema::table('employee_details', function (Blueprint $table) {
            if (! Schema::hasColumn('employee_details', 'device_user_id')) {
                $table->string('device_user_id')->nullable()->after('user_id');
            }
            if (! Schema::hasColumn('employee_details', 'fingerprint_enabled')) {
                $table->boolean('fingerprint_enabled')->default(true)->after('device_user_id');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('employee_details')) {
            return;
        }

        Schema::table('employee_details', function (Blueprint $table) {
            if (Schema::hasColumn('employee_details', 'fingerprint_enabled')) {
                $table->dropColumn('fingerprint_enabled');
            }
            if (Schema::hasColumn('employee_details', 'device_user_id')) {
                $table->dropColumn('device_user_id');
            }
        });
    }
};

