<?php

namespace App\Support;

use Closure;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مساعد اختياري لمايغريشن جديدة — معظم الملفات تستخدم Schema::hasTable / hasColumn مباشرة.
 */
final class MigrationGuards
{
    public static function createUnlessExists(string $table, Closure $callback): void
    {
        if (Schema::hasTable($table)) {
            return;
        }
        Schema::create($table, $callback);
    }

    public static function tableIfExists(string $table, Closure $callback): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }
        Schema::table($table, $callback);
    }

    /**
     * @param  Closure(Blueprint): void  $callback
     */
    public static function addColumnUnlessExists(string $table, string $column, Closure $callback): void
    {
        if (! Schema::hasTable($table) || Schema::hasColumn($table, $column)) {
            return;
        }
        Schema::table($table, $callback);
    }
}
