<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('employee_task_timeline')) {
            return;
        }

        Schema::create('employee_task_timeline', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_task_id')->nullable();
            $table->unsignedBigInteger('occurrence_id')->nullable();
            $table->string('event_type');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_type')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['employee_task_id', 'created_at']);
            $table->index(['occurrence_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_task_timeline');
    }
};
