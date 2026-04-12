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
        Schema::create('incoming_checks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('from_customer')->nullable();
            $table->unsignedBigInteger('from_seller')->nullable();
            $table->unsignedBigInteger('to_customer')->nullable();
            $table->unsignedBigInteger('to_seller')->nullable();

            $table->foreign('from_customer')->references('id')->on('customers')->onDelete('set null');
            $table->foreign('to_customer')->references('id')->on('customers')->onDelete('set null');
            $table->foreign('from_seller')->references('id')->on('sellers')->onDelete('set null');
            $table->foreign('to_seller')->references('id')->on('sellers')->onDelete('set null');
            
            $table->float('total')->default(0);
            $table->date('due_date')->nullable();
            $table->string('currency')->default('dollar');
            $table->string('check_id')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('front_image')->nullable();
            $table->string('back_image')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('incoming_checks');
    }
};
