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
        Schema::table('instant_sales', function (Blueprint $table) {
            if (! Schema::hasColumn('instant_sales', 'project_id')) {
                $table->unsignedBigInteger('project_id')->nullable();
                $table->foreign('project_id')->references('id')->on('projects')->onDelete('set null');
            }

            if (! Schema::hasColumn('instant_sales', 'type')) {
                $table->string('type')->default('normal');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('instant_sales', function (Blueprint $table) {
            //
        });
    }
};
