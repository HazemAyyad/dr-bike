<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_assembly_recipes')) {
            Schema::create('product_assembly_recipes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('target_product_id');
                $table->unsignedBigInteger('target_size_color_id')->nullable();
                $table->string('name')->nullable();
                $table->decimal('unit_cost', 12, 2)->default(0);
                $table->boolean('is_active')->default(true);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->foreign('target_product_id')->references('id')->on('products')->cascadeOnDelete();
                $table->foreign('target_size_color_id')->references('id')->on('size_colors')->nullOnDelete();
                $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('product_assembly_recipe_items')) {
            Schema::create('product_assembly_recipe_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('recipe_id')->constrained('product_assembly_recipes')->cascadeOnDelete();
                $table->unsignedBigInteger('component_product_id');
                $table->unsignedBigInteger('component_size_color_id')->nullable();
                $table->decimal('quantity_per_unit', 12, 3);
                $table->decimal('unit_cost', 12, 2)->default(0);
                $table->timestamps();

                $table->foreign('component_product_id')->references('id')->on('products')->cascadeOnDelete();
                $table->foreign('component_size_color_id')->references('id')->on('size_colors')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('product_assembly_operations')) {
            Schema::create('product_assembly_operations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('recipe_id')->constrained('product_assembly_recipes')->cascadeOnDelete();
                $table->enum('operation_type', ['assemble', 'disassemble']);
                $table->unsignedBigInteger('target_product_id');
                $table->unsignedBigInteger('target_size_color_id')->nullable();
                $table->integer('quantity');
                $table->decimal('unit_cost', 12, 2)->default(0);
                $table->decimal('total_cost', 12, 2)->default(0);
                $table->text('note')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->foreign('target_product_id')->references('id')->on('products')->cascadeOnDelete();
                $table->foreign('target_size_color_id')->references('id')->on('size_colors')->nullOnDelete();
                $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('product_assembly_operation_items')) {
            Schema::create('product_assembly_operation_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('operation_id')->constrained('product_assembly_operations')->cascadeOnDelete();
                $table->unsignedBigInteger('component_product_id');
                $table->unsignedBigInteger('component_size_color_id')->nullable();
                $table->decimal('quantity_per_unit', 12, 3);
                $table->decimal('total_quantity', 12, 3);
                $table->decimal('unit_cost', 12, 2)->default(0);
                $table->decimal('total_cost', 12, 2)->default(0);
                $table->timestamps();

                $table->foreign('component_product_id')->references('id')->on('products')->cascadeOnDelete();
                $table->foreign('component_size_color_id')->references('id')->on('size_colors')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_assembly_operation_items');
        Schema::dropIfExists('product_assembly_operations');
        Schema::dropIfExists('product_assembly_recipe_items');
        Schema::dropIfExists('product_assembly_recipes');
    }
};
