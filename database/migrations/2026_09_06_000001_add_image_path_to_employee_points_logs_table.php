<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('employee_points_logs', 'image_path')) {
            Schema::table('employee_points_logs', function (Blueprint $table) {
                $table->string('image_path')->nullable()->after('notes');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('employee_points_logs', 'image_path')) {
            Schema::table('employee_points_logs', function (Blueprint $table) {
                $table->dropColumn('image_path');
            });
        }
    }
};
