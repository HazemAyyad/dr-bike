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
        Schema::table('projects', function (Blueprint $table) {
            if (! Schema::hasColumn('projects', 'achievement_percentage')) {
                $table->float('achievement_percentage')->nullable();
            }
            if (! Schema::hasColumn('projects', 'status')) {
                $table->string('status')->default('ongoing');
            }

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['achievement percentage', 'status']);
        });
    }
};
