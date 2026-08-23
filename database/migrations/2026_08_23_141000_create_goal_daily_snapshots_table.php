<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('goal_daily_snapshots')) {
            return;
        }

        Schema::create('goal_daily_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goal_id')->constrained('goals')->cascadeOnDelete();
            $table->date('snapshot_date')->index();
            $table->decimal('current_value', 15, 4)->default(0);
            $table->decimal('achievement_percentage', 8, 2)->default(0);
            $table->timestamps();

            $table->unique(['goal_id', 'snapshot_date'], 'goal_daily_snapshots_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goal_daily_snapshots');
    }
};
