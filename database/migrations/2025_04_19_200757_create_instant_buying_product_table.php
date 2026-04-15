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
        if (Schema::hasTable('instant_buying_product')) {
            return;
        }

        Schema::create('instant_buying_product', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('instant_buying_id')->nullable();
            $table->foreign('instant_buying_id')->references('id')->on('instant_buyings')->onDelete('cascade');

            $table->unsignedBigInteger('product_id')->nullable();
            $table->foreign('product_id')->references('id')->on('products')->onDelete('set null');
            $table->integer('quantity')->default(1);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('instant_buying_product');
    }
};
