<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_development_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('app_development_tasks')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('assigned_to_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status', 32)->default('new')->index();
            $table->string('priority', 32)->default('normal')->index();
            $table->unsignedTinyInteger('manual_progress')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['parent_id', 'status']);
            $table->index(['assigned_to_user_id', 'status']);
            $table->index(['created_by_user_id', 'status']);
        });

        Schema::create('app_development_task_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_development_task_id')->constrained('app_development_tasks')->cascadeOnDelete();
            $table->foreignId('sender_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('message_type', 32)->default('text')->index();
            $table->text('body')->nullable();
            $table->timestamps();

            $table->index(['app_development_task_id', 'created_at']);
        });

        Schema::create('app_development_task_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_development_task_id')->constrained('app_development_tasks')->cascadeOnDelete();
            $table->foreignId('app_development_task_message_id')->nullable()->constrained('app_development_task_messages')->cascadeOnDelete();
            $table->string('disk')->default('public');
            $table->string('path');
            $table->string('url')->nullable();
            $table->string('original_name')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('attachment_type', 32)->default('document')->index();
            $table->timestamps();
        });

        Schema::create('app_development_task_status_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_development_task_id')->constrained('app_development_tasks')->cascadeOnDelete();
            $table->foreignId('changed_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('old_status', 32)->nullable();
            $table->string('new_status', 32);
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_development_task_status_logs');
        Schema::dropIfExists('app_development_task_attachments');
        Schema::dropIfExists('app_development_task_messages');
        Schema::dropIfExists('app_development_tasks');
    }
};
