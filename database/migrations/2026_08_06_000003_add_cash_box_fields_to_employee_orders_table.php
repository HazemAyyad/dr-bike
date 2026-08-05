<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employee_orders')) {
            return;
        }

        Schema::table('employee_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('employee_orders', 'approved_box_id')) {
                $table->unsignedBigInteger('approved_box_id')->nullable()->after('rejection_reason');
                $table->index('approved_box_id');
            }
            if (! Schema::hasColumn('employee_orders', 'box_log_id')) {
                $table->unsignedBigInteger('box_log_id')->nullable()->after('approved_box_id');
                $table->index('box_log_id');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('employee_orders')) {
            return;
        }

        Schema::table('employee_orders', function (Blueprint $table) {
            foreach (['box_log_id', 'approved_box_id'] as $column) {
                if (Schema::hasColumn('employee_orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
