<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\RequiresDisposableDatabase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();

        if (str_starts_with(static::class, 'Tests\\Feature\\OnlineStore\\')) {
            $this->requireDisposableDatabase();
        }
    }

    /**
     * Opt-in hook for database-backed tests that do not use the trait directly.
     */
    protected function requireDisposableDatabase(): void
    {
        RequiresDisposableDatabase::assertDisposableDatabase();
    }
}
