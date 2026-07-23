<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('app_development_tasks')) {
            Schema::create('app_development_tasks', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('parent_id')->nullable();
                $table->unsignedBigInteger('created_by_user_id');
                $table->unsignedBigInteger('assigned_to_user_id');
                $table->string('title');
                $table->text('description')->nullable();
                $table->string('status', 32)->default('new')->index('adt_status_idx');
                $table->string('priority', 32)->default('normal')->index('adt_priority_idx');
                $table->unsignedTinyInteger('manual_progress')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('parent_id', 'adt_parent_fk')->references('id')->on('app_development_tasks')->cascadeOnDelete();
                $table->foreign('created_by_user_id', 'adt_creator_fk')->references('id')->on('users')->cascadeOnDelete();
                $table->foreign('assigned_to_user_id', 'adt_assignee_fk')->references('id')->on('users')->cascadeOnDelete();

                $table->index(['parent_id', 'status'], 'adt_parent_status_idx');
                $table->index(['assigned_to_user_id', 'status'], 'adt_assignee_status_idx');
                $table->index(['created_by_user_id', 'status'], 'adt_creator_status_idx');
            });
        }

        if (! Schema::hasTable('app_development_task_messages')) {
            Schema::create('app_development_task_messages', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('app_development_task_id');
                $table->unsignedBigInteger('sender_user_id');
                $table->string('message_type', 32)->default('text')->index('adt_msg_type_idx');
                $table->text('body')->nullable();
                $table->timestamps();

                $table->foreign('app_development_task_id', 'adt_msg_task_fk')->references('id')->on('app_development_tasks')->cascadeOnDelete();
                $table->foreign('sender_user_id', 'adt_msg_sender_fk')->references('id')->on('users')->cascadeOnDelete();

                $table->index(['app_development_task_id', 'created_at'], 'adt_msg_task_created_idx');
            });
        }

        if (! Schema::hasTable('app_development_task_attachments')) {
            Schema::create('app_development_task_attachments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('app_development_task_id');
                $table->unsignedBigInteger('app_development_task_message_id')->nullable();
                $table->string('disk')->default('public');
                $table->string('path');
                $table->string('url')->nullable();
                $table->string('original_name')->nullable();
                $table->string('mime_type')->nullable();
                $table->unsignedBigInteger('size')->default(0);
                $table->string('attachment_type', 32)->default('document')->index('adt_att_type_idx');
                $table->timestamps();

                $table->foreign('app_development_task_id', 'adt_att_task_fk')->references('id')->on('app_development_tasks')->cascadeOnDelete();
                $table->foreign('app_development_task_message_id', 'adt_att_msg_fk')->references('id')->on('app_development_task_messages')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('app_development_task_status_logs')) {
            Schema::create('app_development_task_status_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('app_development_task_id');
                $table->unsignedBigInteger('changed_by_user_id');
                $table->string('old_status', 32)->nullable();
                $table->string('new_status', 32);
                $table->text('note')->nullable();
                $table->timestamps();

                $table->foreign('app_development_task_id', 'adt_log_task_fk')->references('id')->on('app_development_tasks')->cascadeOnDelete();
                $table->foreign('changed_by_user_id', 'adt_log_user_fk')->references('id')->on('users')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('app_development_task_status_logs');
        Schema::dropIfExists('app_development_task_attachments');
        Schema::dropIfExists('app_development_task_messages');
        Schema::dropIfExists('app_development_tasks');
    }
};
