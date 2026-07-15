<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('person_product_price_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_product_setting_id')
                ->constrained('person_product_settings')
                ->cascadeOnDelete();
            $table->unsignedInteger('min_qty');
            $table->unsignedInteger('max_qty')->nullable();
            $table->decimal('unit_price', 12, 2);
            $table->timestamps();

            $table->index(['person_product_setting_id', 'min_qty']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('person_product_price_tiers');
    }
};
