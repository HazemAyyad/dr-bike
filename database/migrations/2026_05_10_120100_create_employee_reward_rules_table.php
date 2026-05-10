<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('employee_reward_rules')) {
            return;
        }

        Schema::create('employee_reward_rules', function (Blueprint $table) {
            $table->id();
            $table->integer('min_points');
            $table->integer('max_points')->nullable();
            $table->decimal('reward_amount', 10, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'min_points']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_reward_rules');
    }
};
