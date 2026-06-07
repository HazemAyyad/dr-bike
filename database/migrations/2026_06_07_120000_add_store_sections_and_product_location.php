<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('store_sections')) {
            Schema::create('store_sections', function (Blueprint $table) {
                $table->id();
                $table->string('name', 120);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasColumn('products', 'store_section_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->unsignedBigInteger('store_section_id')->nullable()->after('category_id');
                $table->string('shelf_number', 30)->nullable()->after('store_section_id');
                $table->foreign('store_section_id')
                    ->references('id')
                    ->on('store_sections')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'store_section_id')) {
                try {
                    $table->dropForeign(['store_section_id']);
                } catch (\Throwable) {
                }
                $table->dropColumn(['store_section_id', 'shelf_number']);
            }
        });

        Schema::dropIfExists('store_sections');
    }
};
