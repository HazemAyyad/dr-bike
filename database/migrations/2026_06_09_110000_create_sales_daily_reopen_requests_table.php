<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sales_daily_reopen_requests')) {
            Schema::create('sales_daily_reopen_requests', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('session_id');
                $table->unsignedBigInteger('requested_by_user_id');
                $table->timestamp('requested_at');
                $table->text('reason');
                $table->string('status', 32)->default('pending');
                $table->unsignedBigInteger('reviewed_by_user_id')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->text('review_notes')->nullable();
                $table->timestamps();

                $table->foreign('session_id')->references('id')->on('sales_daily_sessions')->cascadeOnDelete();
                $table->index('status');
                $table->index(['session_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_daily_reopen_requests');
    }
};
