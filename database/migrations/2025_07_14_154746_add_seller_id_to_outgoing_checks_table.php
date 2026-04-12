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
        Schema::table('outgoing_checks', function (Blueprint $table) {
           $table->unsignedBigInteger('seller_id')->nullable();
           $table->foreign('seller_id')->references('id')->on('sellers')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('outgoing_checks', function (Blueprint $table) {
            //
        });
    }
};
