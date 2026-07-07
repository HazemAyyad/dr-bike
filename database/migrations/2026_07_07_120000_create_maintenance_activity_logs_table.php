<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('maintenance_activity_logs')) {
            return;
        }

        Schema::create('maintenance_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('maintenance_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('actor_name')->nullable();
            $table->string('actor_type')->nullable();
            $table->string('action');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('old_status')->nullable();
            $table->string('new_status')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['maintenance_id', 'created_at']);
            $table->index(['action', 'created_at']);

            $table->foreign('maintenance_id')
                ->references('id')
                ->on('maintenance')
                ->onDelete('cascade');
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_activity_logs');
    }
};
