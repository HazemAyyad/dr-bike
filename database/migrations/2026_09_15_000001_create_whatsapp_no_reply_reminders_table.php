<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_no_reply_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_conversation_id')->constrained('whatsapp_conversations')->cascadeOnDelete();
            $table->foreignId('inbound_message_id')->constrained('whatsapp_messages')->cascadeOnDelete();
            $table->enum('status', ['pending', 'sent', 'failed'])->default('pending')->index();
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique('inbound_message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_no_reply_reminders');
    }
};
