<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('maintenance_payments')) {
            Schema::create('maintenance_payments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('maintenance_id');
                $table->unsignedBigInteger('maintenance_daily_session_id')->nullable();
                $table->unsignedBigInteger('box_id')->nullable();
                $table->unsignedBigInteger('instant_sale_id')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->string('method', 32)->default('cash');
                $table->decimal('amount', 12, 2)->default(0);
                $table->string('currency', 32)->default('شيكل');
                $table->text('note')->nullable();
                $table->timestamps();

                $table->foreign('maintenance_id')->references('id')->on('maintenance')->cascadeOnDelete();
                $table->foreign('maintenance_daily_session_id')->references('id')->on('maintenance_daily_sessions')->nullOnDelete();
                $table->foreign('box_id')->references('id')->on('boxes')->nullOnDelete();
                $table->foreign('instant_sale_id')->references('id')->on('instant_sales')->nullOnDelete();
                $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
                $table->index(['maintenance_id', 'method']);
                $table->index(['maintenance_daily_session_id', 'method']);
            });
        }

        if (Schema::hasTable('maintenance_daily_box_logs')) {
            Schema::table('maintenance_daily_box_logs', function (Blueprint $table) {
                if (! Schema::hasColumn('maintenance_daily_box_logs', 'payment_method')) {
                    $table->string('payment_method', 32)->nullable()->after('type');
                }
                if (! Schema::hasColumn('maintenance_daily_box_logs', 'affects_cash_balance')) {
                    $table->boolean('affects_cash_balance')->default(true)->after('payment_method');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('maintenance_daily_box_logs')) {
            Schema::table('maintenance_daily_box_logs', function (Blueprint $table) {
                foreach (['affects_cash_balance', 'payment_method'] as $column) {
                    if (Schema::hasColumn('maintenance_daily_box_logs', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        Schema::dropIfExists('maintenance_payments');
    }
};
