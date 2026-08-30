<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->restoreAutoIncrementPrimaryKey('expenses', 'BIGINT UNSIGNED');
        $this->restoreAutoIncrementPrimaryKey('migrations', 'INT UNSIGNED');
    }

    private function restoreAutoIncrementPrimaryKey(string $table, string $type): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'id')) {
            return;
        }

        $hasPrimary = collect(DB::select("SHOW INDEX FROM `{$table}`"))
            ->contains(fn ($index) => ($index->Key_name ?? null) === 'PRIMARY');
        if ($hasPrimary) {
            return;
        }

        $invalid = DB::table($table)
            ->select('id')
            ->whereNull('id')
            ->orWhere('id', '<=', 0)
            ->exists();
        $hasDuplicates = DB::table($table)
            ->select('id')
            ->groupBy('id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($invalid || $hasDuplicates) {
            throw new \RuntimeException(
                "Cannot restore {$table}.id primary key: existing identifiers are invalid or duplicated."
            );
        }

        DB::statement(
            "ALTER TABLE `{$table}` ADD PRIMARY KEY (`id`), MODIFY `id` {$type} NOT NULL AUTO_INCREMENT"
        );
    }

    public function down(): void
    {
        // Deliberately non-destructive: restored identifiers must remain stable.
    }
};
