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
        if (Schema::hasTable('employee_details')) {
            return;
        }

        Schema::create('employee_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->integer('points')->default(0);
            $table->float('hour_work_price')->nullable();
            $table->float('overtime_work_price')->nullable();
            $table->integer('number_of_work_hours')->nullable();
            $table->time('start_work_time')->nullable();
            $table->time('end_work_time')->nullable();
            $table->string('job_title')->nullable();
            $table->float('salary')->nullable();
            $table->float('debts')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_details');
    }
};
