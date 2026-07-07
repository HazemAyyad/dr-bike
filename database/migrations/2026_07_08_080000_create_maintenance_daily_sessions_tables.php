<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('maintenance_daily_sessions')) {
            Schema::create('maintenance_daily_sessions', function (Blueprint $table) {
                $table->id();
                $table->date('business_date')->unique();
                $table->string('status', 32)->default('open');
                $table->unsignedBigInteger('box_id')->nullable();
                $table->decimal('opening_balance', 15, 2)->default(0);
                $table->decimal('closing_balance', 15, 2)->nullable();
                $table->timestamp('opened_at')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->unsignedBigInteger('opened_by_user_id')->nullable();
                $table->unsignedBigInteger('closed_by_user_id')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->foreign('box_id')->references('id')->on('boxes')->nullOnDelete();
                $table->foreign('opened_by_user_id')->references('id')->on('users')->nullOnDelete();
                $table->foreign('closed_by_user_id')->references('id')->on('users')->nullOnDelete();
                $table->index(['status', 'business_date']);
            });
        }

        if (! Schema::hasTable('maintenance_daily_box_logs')) {
            Schema::create('maintenance_daily_box_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('session_id');
                $table->unsignedBigInteger('box_id')->nullable();
                $table->unsignedBigInteger('maintenance_id')->nullable();
                $table->unsignedBigInteger('instant_sale_id')->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('actor_name')->nullable();
                $table->string('type', 32)->default('add');
                $table->decimal('amount', 15, 2)->default(0);
                $table->decimal('box_balance_before', 15, 2)->default(0);
                $table->decimal('box_balance_after', 15, 2)->default(0);
                $table->string('description')->nullable();
                $table->text('note')->nullable();
                $table->timestamps();

                $table->foreign('session_id')->references('id')->on('maintenance_daily_sessions')->cascadeOnDelete();
                $table->foreign('box_id')->references('id')->on('boxes')->nullOnDelete();
                $table->foreign('maintenance_id')->references('id')->on('maintenance')->nullOnDelete();
                $table->foreign('instant_sale_id')->references('id')->on('instant_sales')->nullOnDelete();
                $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
                $table->index(['session_id', 'created_at']);
                $table->index(['maintenance_id', 'created_at']);
            });
        }

        Schema::table('maintenance', function (Blueprint $table) {
            if (! Schema::hasColumn('maintenance', 'maintenance_daily_session_id')) {
                $table->unsignedBigInteger('maintenance_daily_session_id')->nullable()->after('payment_box_id');
                $table->foreign('maintenance_daily_session_id')
                    ->references('id')
                    ->on('maintenance_daily_sessions')
                    ->nullOnDelete();
            }
        });

        if (Schema::hasTable('instant_sales') && ! Schema::hasColumn('instant_sales', 'maintenance_daily_session_id')) {
            Schema::table('instant_sales', function (Blueprint $table) {
                $table->unsignedBigInteger('maintenance_daily_session_id')->nullable()->after('sales_daily_session_id');
                $table->foreign('maintenance_daily_session_id')
                    ->references('id')
                    ->on('maintenance_daily_sessions')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('instant_sales') && Schema::hasColumn('instant_sales', 'maintenance_daily_session_id')) {
            Schema::table('instant_sales', function (Blueprint $table) {
                $table->dropForeign(['maintenance_daily_session_id']);
                $table->dropColumn('maintenance_daily_session_id');
            });
        }

        if (Schema::hasTable('maintenance') && Schema::hasColumn('maintenance', 'maintenance_daily_session_id')) {
            Schema::table('maintenance', function (Blueprint $table) {
                $table->dropForeign(['maintenance_daily_session_id']);
                $table->dropColumn('maintenance_daily_session_id');
            });
        }

        Schema::dropIfExists('maintenance_daily_box_logs');
        Schema::dropIfExists('maintenance_daily_sessions');
    }
};
