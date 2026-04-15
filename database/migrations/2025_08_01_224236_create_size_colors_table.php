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
        if (Schema::hasTable('size_colors')) {
            return;
        }

        Schema::create('size_colors', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sizeId')->nullable();
            $table->foreign('sizeId')->references('id')->on('sizes')->onDelete('cascade');

            $table->string('colorAr')->nullable();
            $table->string('colorEn')->nullable();
            $table->string('colorAbbr')->nullable();
            $table->decimal('normailPrice', 10, 2)->default(0);
            $table->decimal('wholesalePrice', 10, 2)->default(0);
            $table->decimal('discount', 10, 2)->default(0);
            $table->unsignedInteger('stock')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('size_colors');
    }
};
