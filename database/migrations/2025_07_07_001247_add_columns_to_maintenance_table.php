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
        Schema::table('maintenance', function (Blueprint $table) {
            $table->time('reciept_time')->nullable();
            $table->json('files')->nullable();

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('maintenance', function (Blueprint $table) {
            $table->dropColumns(['reciept_time','files']);
        });
    }
};
