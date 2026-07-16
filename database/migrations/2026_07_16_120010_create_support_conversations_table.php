<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employee_details')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('employee_suggestion_id')->nullable()->constrained('employee_suggestions')->nullOnDelete();
            $table->string('subject')->nullable();
            $table->string('status')->default('open')->index();
            $table->string('priority')->default('normal')->index();
            $table->text('last_message')->nullable();
            $table->timestamp('last_message_at')->nullable()->index();
            $table->unsignedInteger('employee_unread_count')->default(0);
            $table->unsignedInteger('support_unread_count')->default(0);
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['employee_id', 'status']);
            $table->index(['status', 'last_message_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_conversations');
    }
};
