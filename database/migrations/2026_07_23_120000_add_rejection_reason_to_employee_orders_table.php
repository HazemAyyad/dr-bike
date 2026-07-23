<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('employee_orders', 'rejection_reason')) {
                $table->text('rejection_reason')->nullable()->after('status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('employee_orders', function (Blueprint $table) {
            if (Schema::hasColumn('employee_orders', 'rejection_reason')) {
                $table->dropColumn('rejection_reason');
            }
        });
    }
};
