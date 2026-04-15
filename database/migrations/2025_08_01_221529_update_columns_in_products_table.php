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
            if (! Schema::hasColumn('products', 'nameAr')) {
                $table->string('nameAr');
            }
            if (! Schema::hasColumn('products', 'nameEng')) {
                $table->string('nameEng')->nullable();
            }
            if (! Schema::hasColumn('products', 'nameAbree')) {
                $table->string('nameAbree')->nullable();
            }
            if (! Schema::hasColumn('products', 'isShow')) {
                $table->boolean('isShow')->default(true);
            }
            if (! Schema::hasColumn('products', 'descriptionAr')) {
                $table->text('descriptionAr')->nullable();
            }
            if (! Schema::hasColumn('products', 'descriptionEng')) {
                $table->text('descriptionEng')->nullable();
            }
            if (! Schema::hasColumn('products', 'descriptionAbree')) {
                $table->text('descriptionAbree')->nullable();
            }
            if (! Schema::hasColumn('products', 'videoUrl')) {
                $table->string('videoUrl')->nullable();
            }
            if (! Schema::hasColumn('products', 'normailPrice')) {
                $table->decimal('normailPrice', 10, 2)->default(0);
            }
            if (! Schema::hasColumn('products', 'wholesalePrice')) {
                $table->decimal('wholesalePrice', 10, 2)->default(0);
            }
            if (! Schema::hasColumn('products', 'stock')) {
                $table->integer('stock')->default(0);
            }
            if (! Schema::hasColumn('products', 'model')) {
                $table->string('model')->nullable();
            }
            if (! Schema::hasColumn('products', 'isNewItem')) {
                $table->boolean('isNewItem')->default(true);
            }
            if (! Schema::hasColumn('products', 'isMoreSales')) {
                $table->boolean('isMoreSales')->default(false);
            }
            if (! Schema::hasColumn('products', 'rate')) {
                $table->float('rate')->default(0);
            }
            if (! Schema::hasColumn('products', 'manufactureYear')) {
                $table->integer('manufactureYear')->default(0);
            }
            if (! Schema::hasColumn('products', 'discount')) {
                $table->decimal('discount', 10, 2)->default(0);
            }
            if (! Schema::hasColumn('products', 'userIdAdd')) {
                $table->unsignedBigInteger('userIdAdd')->nullable();
            }
            if (! Schema::hasColumn('products', 'dateAdd')) {
                $table->timestamp('dateAdd')->nullable();
            }
            if (! Schema::hasColumn('products', 'userIdUpdate')) {
                $table->unsignedBigInteger('userIdUpdate')->nullable();
            }
            if (! Schema::hasColumn('products', 'dateUpdate')) {
                $table->timestamp('dateUpdate')->nullable();
            }
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
