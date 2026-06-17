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
            if (! Schema::hasColumn('employee_attendance_scans', 'is_reverse_checkout')) {
                $table->boolean('is_reverse_checkout')
                    ->default(false)
                    ->index()
                    ->after('direction');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('employee_attendance_scans')) {
            return;
        }

        Schema::table('employee_attendance_scans', function (Blueprint $table) {
            if (Schema::hasColumn('employee_attendance_scans', 'is_reverse_checkout')) {
                $table->dropColumn('is_reverse_checkout');
            }
        });
    }
};

