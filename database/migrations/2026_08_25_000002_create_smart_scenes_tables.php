<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('smart_scenes')) {
            Schema::create('smart_scenes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('smart_home_id');
                $table->unsignedBigInteger('smart_room_id')->nullable();
                $table->string('tuya_scene_id', 191)->nullable();
                $table->string('name', 191);
                $table->string('icon', 80)->default('auto_awesome');
                $table->string('color', 24)->default('#2563EB');
                $table->string('trigger_type', 32)->default('manual');
                $table->string('match_type', 16)->default('all');
                $table->json('conditions')->nullable();
                $table->json('actions');
                $table->boolean('enabled')->default(true);
                $table->boolean('show_on_home')->default(true);
                $table->boolean('show_in_room')->default(false);
                $table->timestamp('last_executed_at')->nullable();
                $table->string('last_execution_status', 32)->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['user_id', 'smart_home_id', 'enabled']);
                $table->index(['smart_room_id', 'show_in_room']);
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
                $table->foreign('smart_home_id')->references('id')->on('smart_homes')->cascadeOnDelete();
                $table->foreign('smart_room_id')->references('id')->on('smart_rooms')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('smart_scene_executions')) {
            Schema::create('smart_scene_executions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('smart_scene_id');
                $table->unsignedBigInteger('user_id');
                $table->string('source', 32)->default('app');
                $table->string('status', 32);
                $table->text('message')->nullable();
                $table->json('details')->nullable();
                $table->timestamp('executed_at');
                $table->timestamps();

                $table->index(['smart_scene_id', 'executed_at']);
                $table->foreign('smart_scene_id')->references('id')->on('smart_scenes')->cascadeOnDelete();
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('smart_scene_executions');
        Schema::dropIfExists('smart_scenes');
    }
};
