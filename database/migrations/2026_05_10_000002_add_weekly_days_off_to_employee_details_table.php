<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_details', function (Blueprint $table) {
            if (! Schema::hasColumn('employee_details', 'weekly_days_off')) {
                $table->json('weekly_days_off')->nullable()->after('end_work_time');
            }
        });
    }

    public function down(): void
    {
        Schema::table('employee_details', function (Blueprint $table) {
            if (Schema::hasColumn('employee_details', 'weekly_days_off')) {
                $table->dropColumn('weekly_days_off');
            }
        });
    }
};

