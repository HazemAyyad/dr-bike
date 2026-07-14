<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_alias_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('alias_text');
            $table->string('normalized_alias')->index();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedInteger('times_used')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique(['normalized_alias', 'product_id'], 'product_alias_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_alias_mappings');
    }
};
