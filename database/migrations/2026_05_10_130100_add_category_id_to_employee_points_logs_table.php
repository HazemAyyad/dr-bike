<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_points_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('employee_points_logs', 'category_id')) {
                $table->unsignedBigInteger('category_id')->nullable()->after('category')->index();
                $table->foreign('category_id')
                    ->references('id')
                    ->on('employee_point_categories')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('employee_points_logs', function (Blueprint $table) {
            if (Schema::hasColumn('employee_points_logs', 'category_id')) {
                try {
                    $table->dropForeign(['category_id']);
                } catch (\Throwable $e) {
                    // ignore if missing
                }
                $table->dropColumn('category_id');
            }
        });
    }
};
