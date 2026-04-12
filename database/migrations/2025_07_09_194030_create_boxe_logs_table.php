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
        Schema::create('box_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('from_box_id')->nullable();
            $table->unsignedBigInteger('to_box_id')->nullable();
            $table->foreign('from_box_id')->references('id')->on('boxes')->onDelete('set null');
            $table->foreign('to_box_id')->references('id')->on('boxes')->onDelete('set null');
            $table->text('description')->nullable();
            $table->float('transfered_balance')->default(0);
            $table->unsignedBigInteger('box_id')->nullable();
            $table->foreign('box_id')->references('id')->on('boxes')->onDelete('cascade');



            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('boxe_logs');
    }
};
