<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('smart_device_schedules')) {
            return;
        }

        Schema::create('smart_device_schedules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('smart_device_id');
            $table->unsignedBigInteger('user_id');
            $table->string('name');
            $table->string('command_code', 120);
            $table->json('command_value');
            $table->timestamp('scheduled_at');
            $table->string('repeat_type', 24)->default('once');
            $table->json('repeat_days')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_executed_at')->nullable();
            $table->timestamp('next_run_at')->nullable()->index();
            $table->timestamps();

            $table->index(['smart_device_id', 'enabled']);
            $table->foreign('smart_device_id')->references('id')->on('smart_devices')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smart_device_schedules');
    }
};
