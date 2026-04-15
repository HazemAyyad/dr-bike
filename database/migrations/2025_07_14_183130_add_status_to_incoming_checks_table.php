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
        if (Schema::hasColumn('incoming_checks', 'status')) {
            return;
        }

        Schema::table('incoming_checks', function (Blueprint $table) {
            $table->string('status')->default('not_cashed');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('incoming_checks', 'status')) {
            return;
        }

        Schema::table('incoming_checks', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
