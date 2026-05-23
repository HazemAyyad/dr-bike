<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employee_points_logs')) {
            return;
        }

        DB::statement(
            "ALTER TABLE employee_points_logs MODIFY source ENUM(
                'manual',
                'attendance',
                'overtime',
                'absence',
                'lateness',
                'employee_task'
            ) NOT NULL DEFAULT 'manual'"
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('employee_points_logs')) {
            return;
        }

        DB::statement(
            "ALTER TABLE employee_points_logs MODIFY source ENUM(
                'manual',
                'attendance',
                'overtime',
                'absence',
                'lateness'
            ) NOT NULL DEFAULT 'manual'"
        );
    }
};
