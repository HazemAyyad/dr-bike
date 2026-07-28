<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('social_contacts', function (Blueprint $table) {
            $table->id();
            $table->enum('channel', ['facebook', 'instagram'])->index();
            $table->string('external_id', 64);
            $table->string('name')->nullable();
            $table->text('profile_picture_url')->nullable();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('sellers')->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_message_at')->nullable()->index();
            $table->json('raw_profile')->nullable();
            $table->timestamps();

            $table->unique(['channel', 'external_id']);
        });

        Schema::create('social_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_contact_id')->constrained('social_contacts')->cascadeOnDelete();
            $table->enum('channel', ['facebook', 'instagram'])->index();
            $table->string('external_thread_id', 128)->nullable()->index();
            $table->enum('status', ['open', 'closed', 'pending'])->default('open')->index();
            $table->foreignId('assigned_admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('last_message')->nullable();
            $table->timestamp('last_message_at')->nullable()->index();
            $table->unsignedInteger('unread_count')->default(0);
            $table->timestamps();
        });

        Schema::create('social_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_conversation_id')->nullable()->constrained('social_conversations')->nullOnDelete();
            $table->foreignId('social_contact_id')->nullable()->constrained('social_contacts')->nullOnDelete();
            $table->enum('channel', ['facebook', 'instagram'])->index();
            $table->string('external_sender_id', 64)->nullable()->index();
            $table->string('external_recipient_id', 64)->nullable()->index();
            $table->enum('direction', ['inbound', 'outbound'])->index();
            $table->enum('message_type', ['text', 'image', 'document', 'audio', 'video', 'sticker', 'reaction', 'system'])->default('text');
            $table->longText('body')->nullable();
            $table->text('media_url')->nullable();
            $table->string('meta_message_id')->nullable()->unique();
            $table->string('meta_status')->nullable();
            $table->json('raw_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->enum('status', ['pending', 'sent', 'delivered', 'read', 'failed', 'received'])->default('pending')->index();
            $table->text('error_message')->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_messages');
        Schema::dropIfExists('social_conversations');
        Schema::dropIfExists('social_contacts');
    }
};
