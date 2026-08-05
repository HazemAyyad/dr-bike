<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employee_details')->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('module', 60)->index();
            $table->string('action', 80)->index();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('subject_type', 80)->nullable()->index();
            $table->unsignedBigInteger('subject_id')->nullable()->index();
            $table->decimal('amount', 14, 2)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'created_at']);
            $table->index(['module', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_activity_logs');
    }
};
