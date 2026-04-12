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
        Schema::table('products', function (Blueprint $table) {
            $table->string('nameAr');
            $table->string('nameEng')->nullable();
            $table->string('nameAbree')->nullable();
            $table->boolean('isShow')->default(true);
            $table->text('descriptionAr')->nullable();
            $table->text('descriptionEng')->nullable();
            $table->text('descriptionAbree')->nullable();
            $table->string('videoUrl')->nullable();
            $table->decimal('normailPrice', 10, 2)->default(0);
            $table->decimal('wholesalePrice', 10, 2)->default(0);
            $table->integer('stock')->default(0);
            $table->string('model')->nullable();
            $table->boolean('isNewItem')->default(true);
            $table->boolean('isMoreSales')->default(false);
            $table->float('rate')->default(0);
            $table->integer('manufactureYear')->default(0);
            $table->decimal('discount', 10, 2)->default(0);
            $table->unsignedBigInteger('userIdAdd')->nullable();
            $table->timestamp('dateAdd')->nullable();
            $table->unsignedBigInteger('userIdUpdate')->nullable();
            $table->timestamp('dateUpdate')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            //
        });
    }
};
