<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('conversation_tags')) {
            Schema::create('conversation_tags', function (Blueprint $table) {
                $table->id();
                $table->string('name', 40)->unique();
                $table->string('color', 20)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('conversation_taggables')) {
            Schema::create('conversation_taggables', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tag_id')->constrained('conversation_tags')->cascadeOnDelete();
                $table->enum('channel', ['whatsapp', 'facebook', 'instagram']);
                $table->unsignedBigInteger('conversation_id');
                $table->timestamps();

                $table->unique(['channel', 'conversation_id', 'tag_id'], 'conversation_taggables_unique');
                $table->index(['channel', 'conversation_id'], 'conversation_taggables_lookup');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_taggables');
        Schema::dropIfExists('conversation_tags');
    }
};
