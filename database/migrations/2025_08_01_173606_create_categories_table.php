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
        if (Schema::hasTable('categories')) {
            return;
        }

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name_ar'); // nameAr
            $table->string('name_eng')->nullable(); // nameEng
            $table->string('name_abree')->nullable(); // nameAbree
            $table->text('description_ar')->nullable(); // descriptionAr
            $table->text('description_eng')->nullable(); // descriptionEng
            $table->text('description_abree')->nullable(); // descriptionAbree
            $table->string('image_url')->nullable(); // imageUrl
            $table->boolean('is_show')->default(true); // isShow
            $table->string('user_add')->nullable(); // userAdd
            $table->timestamp('date_add')->nullable(); // dateAdd
            $table->string('user_edit')->nullable(); // userEdit
            $table->timestamp('date_edit')->nullable(); // dateEdit
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
