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
        if (Schema::hasTable('sub_categories')) {
            return;
        }

        Schema::create('sub_categories', function (Blueprint $table) {
            $table->id();
            $table->string('nameAr')->nullable();
            $table->string('nameEng')->nullable();
            $table->string('nameAbree')->nullable();
            $table->text('descriptionAr')->nullable();
            $table->text('descriptionEng')->nullable();
            $table->text('descriptionAbree')->nullable();
            $table->string('imageUrl')->nullable();
            $table->boolean('isShow')->default(true);
            $table->unsignedBigInteger('mainCategoryId');
            $table->string('userAdd')->nullable();
            $table->timestamp('dateAdd')->nullable();
            $table->string('userEdit')->nullable();
            $table->timestamp('dateEdit')->nullable();

            // FK constraint (optional – make sure 'categories' table exists first)
            $table->foreign('mainCategoryId')->references('id')->on('categories')->onDelete('cascade');

            $table->timestamps();

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sub_categories');
    }
};
