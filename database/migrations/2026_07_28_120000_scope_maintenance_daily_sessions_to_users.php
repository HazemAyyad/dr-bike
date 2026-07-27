<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('maintenance_daily_sessions')) {
            return;
        }

        Schema::table('maintenance_daily_sessions', function (Blueprint $table) {
            if (! Schema::hasColumn('maintenance_daily_sessions', 'user_id')) {
                $table->unsignedBigInteger('user_id')->nullable()->after('id');
                $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('maintenance_daily_sessions', 'employee_id')) {
                $table->unsignedBigInteger('employee_id')->nullable()->after('user_id');
                $table->foreign('employee_id')->references('id')->on('employee_details')->nullOnDelete();
            }
        });

        try {
            Schema::table('maintenance_daily_sessions', function (Blueprint $table) {
                $table->dropUnique('maintenance_daily_sessions_business_date_unique');
            });
        } catch (\Throwable $e) {
            // Older databases may already have this index removed.
        }

        $fallbackUserId = DB::table('users')->orderBy('id')->value('id');
        if ($fallbackUserId) {
            DB::table('maintenance_daily_sessions')
                ->whereNull('user_id')
                ->update(['user_id' => $fallbackUserId]);
        }

        Schema::table('maintenance_daily_sessions', function (Blueprint $table) {
            $table->index(['user_id', 'business_date'], 'maintenance_daily_sessions_user_date_index');
            $table->index(['employee_id', 'business_date'], 'maintenance_daily_sessions_employee_date_index');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('maintenance_daily_sessions')) {
            return;
        }

        Schema::table('maintenance_daily_sessions', function (Blueprint $table) {
            try {
                $table->dropIndex('maintenance_daily_sessions_employee_date_index');
            } catch (\Throwable $e) {
            }

            try {
                $table->dropIndex('maintenance_daily_sessions_user_date_index');
            } catch (\Throwable $e) {
            }

            if (Schema::hasColumn('maintenance_daily_sessions', 'employee_id')) {
                $table->dropForeign(['employee_id']);
                $table->dropColumn('employee_id');
            }

            if (Schema::hasColumn('maintenance_daily_sessions', 'user_id')) {
                $table->dropForeign(['user_id']);
                $table->dropColumn('user_id');
            }
        });

        try {
            Schema::table('maintenance_daily_sessions', function (Blueprint $table) {
                $table->unique('business_date');
            });
        } catch (\Throwable $e) {
        }
    }
};
