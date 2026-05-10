<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('employee_points_logs')) {
            return;
        }

        Schema::create('employee_points_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->integer('points');
            $table->enum('operation_type', ['add', 'deduct']);
            $table->string('category')->index();
            $table->enum('source', ['manual', 'attendance', 'overtime', 'absence', 'lateness'])
                ->default('manual')
                ->index();
            $table->string('reason')->nullable();
            $table->text('notes')->nullable();
            $table->date('points_date')->index();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'points_date']);
            $table->index(['employee_id', 'operation_type']);

            $table->foreign('employee_id')
                ->references('id')
                ->on('employee_details')
                ->cascadeOnDelete();

            $table->foreign('created_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_points_logs');
    }
};
