<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('rewards');
    }

    public function down(): void
    {
        if (Schema::hasTable('rewards')) {
            return;
        }

        Schema::create('rewards', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id')->nullable();
            $table->string('reward')->nullable();
            $table->float('price')->default(0);
            $table->timestamps();

            $table->foreign('employee_id')
                ->references('id')
                ->on('employee_details')
                ->cascadeOnDelete();
        });
    }
};
