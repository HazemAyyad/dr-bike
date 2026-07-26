<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->json('body_json')->nullable();
            $table->text('plain_text')->nullable();
            $table->string('color', 32)->nullable();
            $table->string('visibility', 16)->default('private')->index();
            $table->boolean('is_pinned')->default(false)->index();
            $table->boolean('is_archived')->default(false)->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['owner_user_id', 'is_archived', 'updated_at']);
        });

        Schema::create('note_collaborators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('note_id')->constrained('notes')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('permission', 16)->default('view');
            $table->timestamps();

            $table->unique(['note_id', 'user_id']);
            $table->index(['user_id', 'permission']);
        });

        Schema::create('note_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('note_id')->constrained('notes')->cascadeOnDelete();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('disk')->default('public');
            $table->string('path');
            $table->string('url')->nullable();
            $table->string('original_name')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('attachment_type', 16)->default('file')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('note_attachments');
        Schema::dropIfExists('note_collaborators');
        Schema::dropIfExists('notes');
    }
};
