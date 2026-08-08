<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'rotation_date')) {
            return;
        }

        DB::statement('UPDATE products SET rotation_date = NULL WHERE rotation_date IS NOT NULL');
        DB::statement('ALTER TABLE products MODIFY rotation_date DECIMAL(10,2) NULL');
    }

    public function down(): void
    {
        if (! Schema::hasColumn('products', 'rotation_date')) {
            return;
        }

        DB::statement('UPDATE products SET rotation_date = NULL WHERE rotation_date IS NOT NULL');
        DB::statement('ALTER TABLE products MODIFY rotation_date DATE NULL');
    }
};
