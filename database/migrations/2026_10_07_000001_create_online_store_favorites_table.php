<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('online_store_favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('online_store_listing_id')->constrained('online_store_listings')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'online_store_listing_id'], 'osf_user_listing_unique');
            $table->index(['user_id', 'created_at'], 'osf_user_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('online_store_favorites');
    }
};
