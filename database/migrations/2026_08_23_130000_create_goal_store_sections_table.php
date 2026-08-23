<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('goal_store_sections')) {
            return;
        }

        Schema::create('goal_store_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goal_id')->nullable()->constrained('goals')->cascadeOnDelete();
            $table->foreignId('store_section_id')->nullable()->constrained('store_sections')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['goal_id', 'store_section_id'], 'goal_store_sections_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goal_store_sections');
    }
};
