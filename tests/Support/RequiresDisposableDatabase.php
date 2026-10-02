<?php

namespace Tests\Support;

use RuntimeException;

final class RequiresDisposableDatabase
{
    public static function assertDisposableDatabase(): void
    {
        if (! app()->environment('testing')) {
            throw new RuntimeException('Database tests are allowed only in the testing environment.');
        }

        if (! filter_var(env('ONLINE_STORE_DISPOSABLE_DB', false), FILTER_VALIDATE_BOOL)) {
            throw new RuntimeException('Set ONLINE_STORE_DISPOSABLE_DB=true to opt in to disposable database tests.');
        }

        $connection = (string) config('database.default');
        $driver = (string) config("database.connections.{$connection}.driver");
        $database = trim((string) config("database.connections.{$connection}.database"));
        $expected = trim((string) env('ONLINE_STORE_DISPOSABLE_DB_NAME', ''));

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            throw new RuntimeException('Online Store database tests require a disposable MySQL/MariaDB schema.');
        }

        if ($database === '' || $expected === '' || ! hash_equals($expected, $database)) {
            throw new RuntimeException('The resolved database must exactly match ONLINE_STORE_DISPOSABLE_DB_NAME.');
        }

        $normalized = strtolower(str_replace('-', '_', $database));
        $knownUnsafeNames = ['dr_bike', 'dr_bike_new', 'doctor_bike', 'production'];
        $looksDisposable = preg_match('/(^|_)(test|testing|disposable)($|_)/', $normalized) === 1;

        if (in_array($normalized, $knownUnsafeNames, true) || ! $looksDisposable) {
            throw new RuntimeException('The resolved database name is not recognizably disposable.');
        }
    }
}
