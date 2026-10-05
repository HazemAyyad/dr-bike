<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('online_store_listings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained('products')->restrictOnDelete();
            $table->string('status', 20)->default('draft');
            $table->json('name_translations')->nullable();
            $table->json('description_translations')->nullable();
            $table->json('badge_translations')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_new')->default(false);
            $table->boolean('show_on_home')->default(false);
            $table->boolean('show_as_offer')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('readiness_state', 20)->default('incomplete');
            $table->json('readiness_issues')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('hidden_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'sort_order'], 'osl_status_sort_idx');
            $table->index(['readiness_state', 'status'], 'osl_readiness_status_idx');
        });

        Schema::create('online_store_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('online_store_categories')->restrictOnDelete();
            $table->json('name_translations');
            $table->json('description_translations')->nullable();
            $table->string('image_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('show_on_home')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['parent_id', 'is_active', 'sort_order'], 'osc_parent_active_sort_idx');
            $table->index(['show_on_home', 'is_active', 'sort_order'], 'osc_home_active_sort_idx');
        });

        Schema::create('online_store_category_listing', function (Blueprint $table) {
            $table->foreignId('online_store_category_id')->constrained('online_store_categories')->cascadeOnDelete();
            $table->foreignId('online_store_listing_id')->constrained('online_store_listings')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['online_store_category_id', 'online_store_listing_id'], 'oscl_category_listing_unique');
            $table->index('online_store_listing_id', 'oscl_listing_idx');
            $table->index(['online_store_category_id', 'sort_order'], 'oscl_category_sort_idx');
        });

        Schema::create('online_store_media_presentations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('online_store_listing_id')->constrained('online_store_listings')->cascadeOnDelete();
            $table->string('source_type', 30);
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('store_media_path')->nullable();
            $table->json('media_metadata')->nullable();
            $table->boolean('is_main')->default(false);
            $table->boolean('is_visible')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['online_store_listing_id', 'source_type', 'source_id'],
                'osmp_listing_source_unique'
            );
            $table->index(['online_store_listing_id', 'is_visible', 'sort_order'], 'osmp_listing_visible_sort_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('online_store_media_presentations');
        Schema::dropIfExists('online_store_category_listing');
        Schema::dropIfExists('online_store_categories');
        Schema::dropIfExists('online_store_listings');
    }
};
