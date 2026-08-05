<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('punishments');
    }

    public function down(): void
    {
        Schema::create('punishments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->nullable()->constrained('employee_details')->nullOnDelete();
            $table->string('punishment')->nullable();
            $table->float('price')->nullable();
            $table->timestamps();
        });
    }
};
