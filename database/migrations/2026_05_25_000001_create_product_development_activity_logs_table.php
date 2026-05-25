<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('product_development_activity_logs')) {
            return;
        }

        Schema::create('product_development_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_development_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action');
            $table->text('description')->nullable();
            $table->json('changes')->nullable();
            $table->timestamps();

            $table->foreign('product_development_id')
                ->references('id')
                ->on('product_development')
                ->cascadeOnDelete();
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_development_activity_logs');
    }
};
