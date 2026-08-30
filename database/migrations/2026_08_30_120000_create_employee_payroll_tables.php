<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employee_salary_periods')) {
            Schema::create('employee_salary_periods', function (Blueprint $table) {
                $table->id();
                // Imported legacy tables do not consistently retain their primary indexes,
                // so legacy references are indexed without unsafe foreign constraints.
                $table->unsignedBigInteger('employee_id')->index();
                $table->date('salary_month');
                $table->decimal('normal_salary', 14, 2)->default(0);
                $table->decimal('overtime_salary', 14, 2)->default(0);
                $table->decimal('bonuses', 14, 2)->default(0);
                $table->decimal('gross_entitlement', 14, 2)->default(0);
                $table->decimal('advances_applied', 14, 2)->default(0);
                $table->decimal('total_paid', 14, 2)->default(0);
                $table->decimal('remaining', 14, 2)->default(0);
                $table->string('status', 24)->default('calculated');
                $table->json('calculation_snapshot');
                $table->unsignedBigInteger('recognized_expense_id')->nullable()->unique();
                $table->timestamps();

                $table->unique(['employee_id', 'salary_month'], 'salary_period_employee_month_unique');
                $table->index(['salary_month', 'status']);
            });
        }

        if (! Schema::hasTable('salary_payment_batches')) {
            Schema::create('salary_payment_batches', function (Blueprint $table) {
                $table->id();
                $table->date('salary_month');
                $table->foreignId('box_id')->constrained('boxes')->restrictOnDelete();
                $table->foreignId('box_log_id')->nullable()->constrained('box_logs')->nullOnDelete();
                $table->date('payment_date');
                $table->decimal('gross_total', 14, 2)->default(0);
                $table->decimal('advances_total', 14, 2)->default(0);
                $table->decimal('cash_total', 14, 2)->default(0);
                $table->text('notes')->nullable();
                $table->json('invoice_img')->nullable();
                $table->json('media')->nullable();
                $table->unsignedBigInteger('created_by_user_id')->nullable()->index();
                $table->string('status', 24)->default('completed');
                $table->timestamps();

                $table->index(['salary_month', 'payment_date']);
            });
        }

        if (! Schema::hasTable('salary_payment_items')) {
            Schema::create('salary_payment_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('batch_id')->constrained('salary_payment_batches')->restrictOnDelete();
                $table->foreignId('salary_period_id')->constrained('employee_salary_periods')->restrictOnDelete();
                $table->unsignedBigInteger('employee_id')->index();
                $table->decimal('amount_paid', 14, 2);
                $table->decimal('remaining_before', 14, 2);
                $table->decimal('remaining_after', 14, 2);
                $table->string('receipt_status', 24)->default('pending');
                $table->timestamp('received_at')->nullable();
                $table->string('employee_signature_path')->nullable();
                $table->string('employee_signature_hash', 64)->nullable();
                $table->string('receipt_hash', 64)->nullable()->unique();
                $table->ipAddress('acknowledgment_ip')->nullable();
                $table->string('acknowledgment_device', 500)->nullable();
                $table->text('dispute_reason')->nullable();
                $table->timestamp('disputed_at')->nullable();
                $table->timestamps();

                $table->index(['employee_id', 'receipt_status']);
                $table->index(['salary_period_id', 'created_at']);
            });
        }

        if (! Schema::hasTable('employee_advance_applications')) {
            Schema::create('employee_advance_applications', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('employee_order_id')->index();
                $table->foreignId('salary_period_id')->constrained('employee_salary_periods')->restrictOnDelete();
                $table->decimal('amount', 14, 2);
                $table->timestamps();

                $table->unique(['employee_order_id', 'salary_period_id'], 'advance_salary_period_unique');
                $table->index('salary_period_id');
            });
        }

        if (Schema::hasTable('expenses') && ! Schema::hasColumn('expenses', 'salary_period_id')) {
            Schema::table('expenses', function (Blueprint $table) {
                $table->unsignedBigInteger('salary_period_id')->nullable()->after('employee_id')->unique();
                $table->foreign('salary_period_id')->references('id')->on('employee_salary_periods')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        // Payroll history and employee signatures are intentionally retained.
    }
};
