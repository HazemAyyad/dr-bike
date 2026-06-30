<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('person_product_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->cascadeOnDelete();
            $table->foreignId('seller_id')->nullable()->constrained('sellers')->cascadeOnDelete();
            $table->decimal('custom_price', 12, 2)->nullable();
            $table->boolean('is_hidden')->default(false);
            $table->timestamps();

            $table->unique(['customer_id', 'product_id']);
            $table->unique(['seller_id', 'product_id']);
            $table->index(['product_id', 'is_hidden']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('person_product_settings');
    }
};
