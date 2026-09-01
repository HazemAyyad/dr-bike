<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employee_orders')
            || ! Schema::hasColumn('employee_orders', 'cancellation_reason')) {
            return;
        }

        DB::statement(
            'ALTER TABLE `employee_orders` '
            .'MODIFY `cancellation_reason` TEXT '
            .'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL'
        );
    }

    public function down(): void
    {
        // Keep utf8mb4 on rollback: converting existing Arabic text back to a
        // legacy character set could corrupt stored cancellation reasons.
    }
};
