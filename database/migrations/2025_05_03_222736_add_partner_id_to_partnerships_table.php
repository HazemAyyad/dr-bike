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
        Schema::table('partnerships', function (Blueprint $table) {
            if (! Schema::hasColumn('partnerships', 'partner_id')) {
                $table->unsignedBigInteger('partner_id')->nullable();
            }
            if (! Schema::hasColumn('partnerships', 'partner_id')) {
                $table->foreign('partner_id')->references('id')->on('partners')->onDelete('cascade');
            }

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('partnerships', function (Blueprint $table) {
            //
        });
    }
};
