<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('contact_categories')) {
            Schema::create('contact_categories', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('color', 20)->default('#2196F3');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('contact_category_assignments')) {
            Schema::create('contact_category_assignments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('contact_category_id');
                $table->unsignedBigInteger('customer_id')->nullable();
                $table->unsignedBigInteger('seller_id')->nullable();
                $table->timestamps();

                $table->foreign('contact_category_id', 'contact_cat_assign_cat_fk')
                    ->references('id')
                    ->on('contact_categories')
                    ->cascadeOnDelete();
                $table->foreign('customer_id', 'contact_cat_assign_customer_fk')->references('id')->on('customers')->cascadeOnDelete();
                $table->foreign('seller_id', 'contact_cat_assign_seller_fk')->references('id')->on('sellers')->cascadeOnDelete();
                $table->unique(['contact_category_id', 'customer_id'], 'contact_cat_customer_unique');
                $table->unique(['contact_category_id', 'seller_id'], 'contact_cat_seller_unique');
                $table->index(['customer_id', 'seller_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_category_assignments');
        Schema::dropIfExists('contact_categories');
    }
};
