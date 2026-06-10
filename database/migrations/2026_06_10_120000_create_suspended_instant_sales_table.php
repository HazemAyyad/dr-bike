<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('suspended_instant_sales')) {
            return;
        }

        Schema::create('suspended_instant_sales', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sales_daily_session_id')->nullable();
            $table->unsignedBigInteger('created_by_user_id');
            $table->unsignedBigInteger('employee_id')->nullable();
            $table->string('reference_code', 32)->nullable();
            $table->string('current_step', 32)->default('product_picker');
            $table->json('payload');
            $table->string('summary_label', 500)->nullable();
            $table->decimal('total_cost', 15, 2)->default(0);
            $table->string('status', 32)->default('suspended');
            $table->unsignedBigInteger('completed_instant_sale_id')->nullable();
            $table->unsignedBigInteger('completed_by_user_id')->nullable();
            $table->unsignedBigInteger('cancelled_by_user_id')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->foreign('sales_daily_session_id')
                ->references('id')
                ->on('sales_daily_sessions')
                ->nullOnDelete();
            $table->foreign('created_by_user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
            $table->foreign('employee_id')
                ->references('id')
                ->on('employee_details')
                ->nullOnDelete();
            $table->foreign('completed_instant_sale_id')
                ->references('id')
                ->on('instant_sales')
                ->nullOnDelete();
            $table->foreign('completed_by_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
            $table->foreign('cancelled_by_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->index(['status', 'created_by_user_id']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suspended_instant_sales');
    }
};
