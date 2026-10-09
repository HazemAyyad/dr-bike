<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('online_store_popup_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('image_path')->nullable();
            $table->json('title_translations');
            $table->json('content_translations')->nullable();
            $table->json('button_translations')->nullable();
            $table->string('theme', 30)->default('brand');
            $table->string('audience_type', 30)->default('all');
            $table->unsignedSmallInteger('audience_days')->nullable();
            $table->string('display_frequency', 30)->default('once');
            $table->string('action_type', 30)->default('none');
            $table->unsignedBigInteger('action_target_id')->nullable();
            $table->string('action_url', 2048)->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedInteger('priority')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['is_active', 'priority']);
        });

        Schema::create('online_store_popup_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('online_store_popup_campaigns')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->char('visitor_hash', 64);
            $table->char('session_hash', 64)->nullable();
            $table->string('event_type', 20);
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['campaign_id', 'event_type']);
            $table->index(['campaign_id', 'user_id', 'event_type'], 'popup_events_campaign_user_event_index');
            $table->index(['campaign_id', 'visitor_hash', 'event_type'], 'popup_events_campaign_visitor_event_index');
            $table->index(['campaign_id', 'session_hash', 'event_type'], 'popup_events_campaign_session_event_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('online_store_popup_events');
        Schema::dropIfExists('online_store_popup_campaigns');
    }
};
