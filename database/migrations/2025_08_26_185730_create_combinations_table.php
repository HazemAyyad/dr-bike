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
        if (Schema::hasTable('combinations')) {
            return;
        }

        Schema::create('combinations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('main_product_id')->nullable();
            $table->unsignedBigInteger('added_product_id')->nullable();
            $table->foreign('main_product_id')->references('id')->on('products')->onDelete('cascade');
            $table->foreign('added_product_id')->references('id')->on('products')->onDelete('cascade');

            $table->integer('quantity')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('combinations');
    }
};
