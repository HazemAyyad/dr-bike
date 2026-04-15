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
        if (Schema::hasTable('incoming_check_boxes')) {
            return;
        }

        Schema::create('incoming_check_boxes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('incoming_check_id')->nullable();
            $table->unsignedBigInteger('box_id')->nullable();
            $table->foreign('incoming_check_id')->references('id')->on('incoming_checks')->onDelete('set null');
            $table->foreign('box_id')->references('id')->on('boxes')->onDelete('set null');
            $table->string('status')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('incoming_check_boxes');
    }
};
