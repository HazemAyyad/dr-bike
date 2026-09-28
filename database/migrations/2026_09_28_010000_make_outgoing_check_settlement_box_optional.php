<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outgoing_check_settlements', function (Blueprint $table) {
            $table->foreignId('box_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('outgoing_check_settlements', function (Blueprint $table) {
            $table->foreignId('box_id')->nullable(false)->change();
        });
    }
};
