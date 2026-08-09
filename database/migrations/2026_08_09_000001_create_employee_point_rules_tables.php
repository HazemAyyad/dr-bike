<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('employee_points_logs')) {
            DB::statement(
                "ALTER TABLE employee_points_logs MODIFY source ENUM(
                    'manual',
                    'attendance',
                    'overtime',
                    'absence',
                    'lateness',
                    'employee_task',
                    'rule_engine'
                ) NOT NULL DEFAULT 'manual'"
            );
        }

        if (! Schema::hasTable('employee_point_rules')) {
            Schema::create('employee_point_rules', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->text('description')->nullable();
                $table->string('condition_type', 80)->index();
                $table->enum('period_type', ['daily', 'weekly', 'monthly'])->default('daily')->index();
                $table->enum('operation_type', ['add', 'deduct'])->default('add');
                $table->unsignedInteger('default_points')->default(0);
                $table->boolean('applies_to_all')->default(true)->index();
                $table->json('settings')->nullable();
                $table->date('effective_from')->nullable()->index();
                $table->boolean('is_active')->default(true)->index();
                $table->unsignedInteger('sort_order')->default(0);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('employee_point_rule_employees')) {
            Schema::create('employee_point_rule_employees', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('rule_id');
                $table->unsignedBigInteger('employee_id');
                $table->timestamps();

                $table->unique(['rule_id', 'employee_id'], 'epr_emp_unique');
                $table->foreign('rule_id')->references('id')->on('employee_point_rules')->cascadeOnDelete();
                $table->foreign('employee_id')->references('id')->on('employee_details')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('employee_point_rule_overrides')) {
            Schema::create('employee_point_rule_overrides', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('rule_id');
                $table->unsignedBigInteger('employee_id');
                $table->unsignedInteger('points')->nullable();
                $table->enum('operation_type', ['add', 'deduct'])->nullable();
                $table->boolean('is_excluded')->default(false);
                $table->date('effective_from')->nullable()->index();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['rule_id', 'employee_id', 'effective_from'], 'epr_overrides_lookup');
                $table->foreign('rule_id')->references('id')->on('employee_point_rules')->cascadeOnDelete();
                $table->foreign('employee_id')->references('id')->on('employee_details')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('employee_point_rule_executions')) {
            Schema::create('employee_point_rule_executions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('rule_id');
                $table->unsignedBigInteger('employee_id');
                $table->unsignedBigInteger('points_log_id')->nullable();
                $table->enum('period_type', ['daily', 'weekly', 'monthly'])->index();
                $table->date('period_start')->index();
                $table->date('period_end')->index();
                $table->string('status', 24)->default('skipped')->index();
                $table->string('reason')->nullable();
                $table->json('details')->nullable();
                $table->timestamps();

                $table->unique(['rule_id', 'employee_id', 'period_type', 'period_start'], 'epr_execution_unique');
                $table->foreign('rule_id')->references('id')->on('employee_point_rules')->cascadeOnDelete();
                $table->foreign('employee_id')->references('id')->on('employee_details')->cascadeOnDelete();
                $table->foreign('points_log_id')->references('id')->on('employee_points_logs')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_point_rule_executions');
        Schema::dropIfExists('employee_point_rule_overrides');
        Schema::dropIfExists('employee_point_rule_employees');
        Schema::dropIfExists('employee_point_rules');

        if (Schema::hasTable('employee_points_logs')) {
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
    }
};
