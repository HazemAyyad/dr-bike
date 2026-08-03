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
            if (! Schema::hasColumn('employee_tasks', 'display_number')) {
                $table->unsignedInteger('display_number')->nullable()->after('id')->index();
            }
        });

        if (Schema::hasTable('employee_task_templates')) {
            Schema::table('employee_task_templates', function (Blueprint $table) {
                if (! Schema::hasColumn('employee_task_templates', 'display_number')) {
                    $table->unsignedInteger('display_number')->nullable()->after('id')->index();
                }
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('employee_tasks')) {
            return;
        }

        Schema::table('employee_tasks', function (Blueprint $table) {
            if (Schema::hasColumn('employee_tasks', 'display_number')) {
                $table->dropColumn('display_number');
            }
        });

        if (Schema::hasTable('employee_task_templates')) {
            Schema::table('employee_task_templates', function (Blueprint $table) {
                if (Schema::hasColumn('employee_task_templates', 'display_number')) {
                    $table->dropColumn('display_number');
                }
            });
        }
    }
};
