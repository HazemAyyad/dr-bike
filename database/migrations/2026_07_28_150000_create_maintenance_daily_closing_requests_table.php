<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('maintenance_daily_closing_requests')) {
            return;
        }

        Schema::create('maintenance_daily_closing_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('session_id');
            $table->unsignedBigInteger('requested_by_user_id');
            $table->timestamp('requested_at');
            $table->string('status', 32)->default('pending');
            $table->unsignedBigInteger('reviewed_by_user_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->unsignedInteger('maintenances_count')->default(0);
            $table->json('cash_counts')->nullable();
            $table->json('transfers')->nullable();
            $table->timestamps();

            $table->foreign('session_id')
                ->references('id')
                ->on('maintenance_daily_sessions')
                ->cascadeOnDelete();
            $table->foreign('requested_by_user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
            $table->foreign('reviewed_by_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_daily_closing_requests');
    }
};
