<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('partnerships') || ! Schema::hasTable('partners')) {
            return;
        }
        Schema::rename('partners', 'partnerships');
    }

    public function down(): void
    {
        if (Schema::hasTable('partners') || ! Schema::hasTable('partnerships')) {
            return;
        }
        Schema::rename('partnerships', 'partners');
    }
};
