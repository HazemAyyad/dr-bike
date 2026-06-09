<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sales_daily_sessions')) {
            Schema::create('sales_daily_sessions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('employee_id')->nullable();
                $table->date('business_date');
                $table->string('status', 32)->default('open');
                $table->json('opening_balances')->nullable();
                $table->timestamp('opened_at')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->unsignedBigInteger('opened_by_user_id')->nullable();
                $table->unsignedBigInteger('closed_by_user_id')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
                $table->foreign('employee_id')->references('id')->on('employee_details')->nullOnDelete();
                $table->index(['user_id', 'business_date']);
                $table->index(['user_id', 'status']);
            });
        }

        if (! Schema::hasTable('sales_daily_closing_requests')) {
            Schema::create('sales_daily_closing_requests', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('session_id');
                $table->unsignedBigInteger('requested_by_user_id');
                $table->timestamp('requested_at');
                $table->string('status', 32)->default('pending');
                $table->unsignedBigInteger('reviewed_by_user_id')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->text('review_notes')->nullable();
                $table->unsignedInteger('instant_sales_count')->default(0);
                $table->unsignedInteger('profit_sales_count')->default(0);
                $table->json('cash_counts')->nullable();
                $table->json('transfers')->nullable();
                $table->timestamps();

                $table->foreign('session_id')->references('id')->on('sales_daily_sessions')->cascadeOnDelete();
                $table->index('status');
            });
        }

        if (! Schema::hasTable('sales_cancellation_requests')) {
            Schema::create('sales_cancellation_requests', function (Blueprint $table) {
                $table->id();
                $table->string('sale_type', 16);
                $table->unsignedBigInteger('sale_id');
                $table->unsignedBigInteger('session_id')->nullable();
                $table->unsignedBigInteger('requested_by_user_id');
                $table->timestamp('requested_at');
                $table->text('reason');
                $table->string('status', 32)->default('pending');
                $table->unsignedBigInteger('reviewed_by_user_id')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->text('review_notes')->nullable();
                $table->unsignedBigInteger('reversal_box_id')->nullable();
                $table->timestamps();

                $table->index(['sale_type', 'sale_id']);
                $table->index('status');
            });
        }

        if (Schema::hasTable('boxes')) {
            Schema::table('boxes', function (Blueprint $table) {
                if (! Schema::hasColumn('boxes', 'user_id')) {
                    $table->unsignedBigInteger('user_id')->nullable()->after('employee_id');
                    $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
                }
            });
        }

        if (Schema::hasTable('instant_sales') && ! Schema::hasColumn('instant_sales', 'sales_daily_session_id')) {
            Schema::table('instant_sales', function (Blueprint $table) {
                $table->unsignedBigInteger('sales_daily_session_id')->nullable()->after('updated_by');
                $table->foreign('sales_daily_session_id')->references('id')->on('sales_daily_sessions')->nullOnDelete();
            });
        }

        if (Schema::hasTable('profit_sales') && ! Schema::hasColumn('profit_sales', 'sales_daily_session_id')) {
            Schema::table('profit_sales', function (Blueprint $table) {
                $table->unsignedBigInteger('sales_daily_session_id')->nullable();
                $table->foreign('sales_daily_session_id')->references('id')->on('sales_daily_sessions')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('profit_sales') && Schema::hasColumn('profit_sales', 'sales_daily_session_id')) {
            Schema::table('profit_sales', function (Blueprint $table) {
                $table->dropForeign(['sales_daily_session_id']);
                $table->dropColumn('sales_daily_session_id');
            });
        }

        if (Schema::hasTable('instant_sales') && Schema::hasColumn('instant_sales', 'sales_daily_session_id')) {
            Schema::table('instant_sales', function (Blueprint $table) {
                $table->dropForeign(['sales_daily_session_id']);
                $table->dropColumn('sales_daily_session_id');
            });
        }

        if (Schema::hasTable('boxes') && Schema::hasColumn('boxes', 'user_id')) {
            Schema::table('boxes', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
                $table->dropColumn('user_id');
            });
        }

        Schema::dropIfExists('sales_cancellation_requests');
        Schema::dropIfExists('sales_daily_closing_requests');
        Schema::dropIfExists('sales_daily_sessions');
    }
};
