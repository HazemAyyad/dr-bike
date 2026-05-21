<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employee_tasks')) {
            return;
        }

        Schema::table('employee_tasks', function (Blueprint $table) {
            if (! Schema::hasColumn('employee_tasks', 'reminder_before_minutes')) {
                $table->unsignedInteger('reminder_before_minutes')->nullable()->after('requires_admin_review');
            }
            if (! Schema::hasColumn('employee_tasks', 'reminder_channel')) {
                $table->string('reminder_channel', 16)->nullable()->after('reminder_before_minutes');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('employee_tasks')) {
            return;
        }

        Schema::table('employee_tasks', function (Blueprint $table) {
            if (Schema::hasColumn('employee_tasks', 'reminder_channel')) {
                $table->dropColumn('reminder_channel');
            }
            if (Schema::hasColumn('employee_tasks', 'reminder_before_minutes')) {
                $table->dropColumn('reminder_before_minutes');
            }
        });
    }
};
