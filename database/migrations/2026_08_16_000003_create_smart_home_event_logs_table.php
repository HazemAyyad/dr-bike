<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('smart_home_event_logs')) {
            Schema::create('smart_home_event_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->unsignedBigInteger('smart_home_id')->nullable();
                $table->string('event', 100)->index();
                $table->boolean('success')->default(false)->index();
                $table->string('error_code')->nullable();
                $table->text('message')->nullable();
                $table->json('context')->nullable();
                $table->timestamps();

                $table->index(['smart_home_id', 'created_at']);
                $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
                $table->foreign('smart_home_id')->references('id')->on('smart_homes')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('smart_home_event_logs');
    }
};
