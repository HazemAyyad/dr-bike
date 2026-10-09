<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('online_store_notification_broadcasts', function (Blueprint $table) {
            $table->id();
            $table->json('title_translations');
            $table->json('body_translations');
            $table->string('audience_type', 30)->default('all');
            $table->unsignedSmallInteger('audience_days')->nullable();
            $table->string('destination_type', 30)->default('home');
            $table->unsignedBigInteger('destination_id')->nullable();
            $table->string('destination_url', 2048)->nullable();
            $table->unsignedInteger('recipient_count')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('push_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->foreignId('popup_campaign_id')->nullable()
                ->constrained('online_store_popup_campaigns')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['audience_type', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('online_store_notification_broadcasts');
    }
};
