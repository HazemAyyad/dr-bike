<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('goal_sub_categories')) {
            return;
        }

        Schema::create('goal_sub_categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('goal_id')->nullable();
            $table->unsignedBigInteger('sub_category_id')->nullable();

            $table->foreign('goal_id')->references('id')->on('goals')->onDelete('cascade');
            $table->foreign('sub_category_id')->references('id')->on('sub_categories')->onDelete('cascade');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('goal_sub_categories');
    }
};
