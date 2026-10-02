<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('online_store_home_sections', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->string('section_type', 30);
            $table->json('title_translations')->nullable();
            $table->string('selection_mode', 30);
            $table->json('selection_config')->nullable();
            $table->boolean('is_visible')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['is_visible', 'sort_order'], 'oshs_visible_sort_idx');
        });

        Schema::create('online_store_home_section_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('home_section_id')->constrained('online_store_home_sections')->cascadeOnDelete();
            $table->string('target_type', 20);
            $table->unsignedBigInteger('target_id');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['home_section_id', 'target_type', 'target_id'], 'oshsi_section_target_unique');
            $table->index(['home_section_id', 'sort_order', 'id'], 'oshsi_section_sort_idx');
            $table->index(['target_type', 'target_id'], 'oshsi_target_idx');
        });

        Schema::create('online_store_banners', function (Blueprint $table) {
            $table->id();
            $table->string('image_path');
            $table->json('title_translations')->nullable();
            $table->json('content_translations')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('action_type', 20)->default('none');
            $table->unsignedBigInteger('action_target_id')->nullable();
            $table->string('action_url', 2048)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['is_active', 'starts_at', 'ends_at', 'sort_order'], 'osb_active_window_sort_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('online_store_banners');
        Schema::dropIfExists('online_store_home_section_items');
        Schema::dropIfExists('online_store_home_sections');
    }
};
