<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_app_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('app', 40)->default('admin');
            $table->string('platform', 32);
            $table->string('device_key', 120);
            $table->string('device_name')->nullable();
            $table->string('version', 40)->nullable();
            $table->unsignedInteger('build')->default(0);
            $table->string('fcm_token', 512)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'app', 'platform', 'device_key'], 'user_app_versions_device_unique');
            $table->index(['app', 'platform', 'build']);
            $table->index('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_app_versions');
    }
};
