<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cron_job_logs')) {
            return;
        }

        Schema::create('cron_job_logs', function (Blueprint $table) {
            $table->id();
            $table->string('job_name');
            $table->string('command_name')->nullable();
            $table->enum('status', ['running', 'success', 'failed'])->default('running');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->longText('output')->nullable();
            $table->longText('error_message')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['status', 'started_at']);
            $table->index('job_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cron_job_logs');
    }
};
