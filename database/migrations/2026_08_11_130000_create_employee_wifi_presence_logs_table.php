<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_wifi_presence_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_detail_id')->constrained('employee_details')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ssid')->nullable();
            $table->boolean('wifi_connected')->default(false);
            $table->boolean('network_connected')->default(false);
            $table->string('state', 20)->default('red');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->timestamps();

            $table->index(['employee_detail_id', 'started_at']);
            $table->index(['state', 'started_at']);
            $table->index('ssid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_wifi_presence_logs');
    }
};
