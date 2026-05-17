<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('offer_packages')) {
            Schema::create('offer_packages', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->decimal('price', 12, 2)->default(0);
                $table->string('image_path')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('offer_package_items')) {
            Schema::create('offer_package_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('offer_package_id')->constrained('offer_packages')->cascadeOnDelete();
                $table->unsignedBigInteger('product_id');
                $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
                $table->unsignedInteger('quantity')->default(1);
                $table->timestamps();
            });
        }

        if (Schema::hasTable('instant_sales') && ! Schema::hasColumn('instant_sales', 'offer_package_id')) {
            Schema::table('instant_sales', function (Blueprint $table) {
                $table->foreignId('offer_package_id')
                    ->nullable()
                    ->after('product_id')
                    ->constrained('offer_packages')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('instant_sales') && Schema::hasColumn('instant_sales', 'offer_package_id')) {
            Schema::table('instant_sales', function (Blueprint $table) {
                $table->dropConstrainedForeignId('offer_package_id');
            });
        }

        Schema::dropIfExists('offer_package_items');
        Schema::dropIfExists('offer_packages');
    }
};
