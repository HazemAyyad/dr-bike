<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('sub_employee_tasks')) {
            return;
        }

        Schema::create('sub_employee_tasks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_task_id')->nullable();
            $table->foreign('employee_task_id')->references('id')->on('employee_tasks')->onDelete('cascade');
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sub_employee_task');
    }
};
