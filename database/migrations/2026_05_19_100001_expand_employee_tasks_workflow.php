<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_tasks', function (Blueprint $table) {
            if (! Schema::hasColumn('employee_tasks', 'priority')) {
                $table->string('priority')->default('medium')->after('points');
            }
            if (! Schema::hasColumn('employee_tasks', 'rejection_notes')) {
                $table->text('rejection_notes')->nullable()->after('notes');
            }
            if (! Schema::hasColumn('employee_tasks', 'started_at')) {
                $table->timestamp('started_at')->nullable()->after('end_time');
            }
            if (! Schema::hasColumn('employee_tasks', 'submitted_at')) {
                $table->timestamp('submitted_at')->nullable()->after('started_at');
            }
            if (! Schema::hasColumn('employee_tasks', 'reviewed_at')) {
                $table->timestamp('reviewed_at')->nullable()->after('submitted_at');
            }
            if (! Schema::hasColumn('employee_tasks', 'template_id')) {
                $table->unsignedBigInteger('template_id')->nullable()->after('parent_id');
            }
            if (! Schema::hasColumn('employee_tasks', 'occurrence_id')) {
                $table->unsignedBigInteger('occurrence_id')->nullable()->after('template_id');
            }
        });

        if (Schema::hasTable('employee_tasks')) {
            DB::table('employee_tasks')
                ->where('status', 'ongoing')
                ->update(['status' => 'pending']);
        }

        Schema::table('sub_employee_tasks', function (Blueprint $table) {
            if (! Schema::hasColumn('sub_employee_tasks', 'bonus_points')) {
                $table->unsignedInteger('bonus_points')->default(0)->after('is_forced_to_upload_img');
            }
            if (! Schema::hasColumn('sub_employee_tasks', 'occurrence_id')) {
                $table->unsignedBigInteger('occurrence_id')->nullable()->after('employee_task_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('employee_tasks', function (Blueprint $table) {
            foreach (['priority', 'rejection_notes', 'started_at', 'submitted_at', 'reviewed_at', 'template_id', 'occurrence_id'] as $col) {
                if (Schema::hasColumn('employee_tasks', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('sub_employee_tasks', function (Blueprint $table) {
            foreach (['bonus_points', 'occurrence_id'] as $col) {
                if (Schema::hasColumn('sub_employee_tasks', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        DB::table('employee_tasks')
            ->where('status', 'pending')
            ->update(['status' => 'ongoing']);
    }
};
