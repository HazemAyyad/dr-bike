<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('instant_sales')) {
            return;
        }

        $columns = [
            'buyer_name' => 'VARCHAR(255) NULL',
            'buyer_phone' => 'VARCHAR(50) NULL',
            'buyer_address' => 'TEXT NULL',
            'buyer_type' => 'VARCHAR(20) NULL',
            'notes' => 'TEXT NULL',
        ];

        foreach ($columns as $column => $definition) {
            if (Schema::hasColumn('instant_sales', $column)) {
                DB::statement(
                    "ALTER TABLE `instant_sales` MODIFY `{$column}` {$definition} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
                );
            }
        }
    }

    public function down(): void
    {
        // No rollback — charset upgrade is safe to keep.
    }
};
