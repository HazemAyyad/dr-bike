<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('store_section_shelves');

        if (Schema::hasColumn('products', 'shelf_number')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('shelf_number');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('products', 'shelf_number')) {
            Schema::table('products', function (Blueprint $table) {
                $table->string('shelf_number', 30)->nullable()->after('store_section_id');
            });
        }

        if (! Schema::hasTable('store_section_shelves')) {
            Schema::create('store_section_shelves', function (Blueprint $table) {
                $table->id();
                $table->foreignId('store_section_id')->constrained('store_sections')->cascadeOnDelete();
                $table->string('shelf_number', 30);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();
                $table->unique(['store_section_id', 'shelf_number']);
            });
        }
    }
};
