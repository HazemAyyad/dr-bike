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

        // MariaDB/MySQL: CHARSET must come before NULL (not after).
        $columns = [
            'buyer_name' => 'VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL',
            'buyer_phone' => 'VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL',
            'buyer_address' => 'TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL',
            'buyer_type' => 'VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL',
            'notes' => 'TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL',
        ];

        foreach ($columns as $column => $definition) {
            if (! Schema::hasColumn('instant_sales', $column)) {
                continue;
            }

            DB::statement(
                "ALTER TABLE `instant_sales` MODIFY `{$column}` {$definition}"
            );
        }
    }

    public function down(): void
    {
        // No rollback — charset upgrade is safe to keep.
    }
};
