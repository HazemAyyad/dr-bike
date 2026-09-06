<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('instant_sale_revisions')) {
            return;
        }

        Schema::create('instant_sale_revisions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('instant_sale_id');
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('action', 32);
            $table->json('before_snapshot')->nullable();
            $table->json('after_snapshot')->nullable();
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('instant_sale_id')
                ->references('id')
                ->on('instant_sales')
                ->cascadeOnDelete();
            $table->foreign('actor_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
            $table->index(['instant_sale_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instant_sale_revisions');
    }
};
