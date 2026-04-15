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
        if (Schema::hasTable('papers')) {
            return;
        }

        Schema::create('papers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->unsignedBigInteger('treasury_id')->nullable();
            $table->unsignedBigInteger('file_box_id')->nullable();
            $table->unsignedBigInteger('file_id')->nullable();
            $table->foreign('treasury_id')->references('id')->on('treasuries')->onDelete('set null');
            $table->foreign('file_box_id')->references('id')->on('file_boxes')->onDelete('set null');
            $table->foreign('file_id')->references('id')->on('files')->onDelete('set null');
            $table->string('img')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('papers');
    }
};
