<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\RequiresDisposableDatabase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;
    use \Tests\Support\StoreTestSchema;

    protected function refreshApplication(): void
    {
        parent::refreshApplication();
        // This runs before Laravel invokes RefreshDatabase in setUpTraits.
        if (str_contains(static::class, '\\OnlineStore\\')) {
            $this->requireDisposableDatabase();
            \Illuminate\Support\Facades\Http::preventStrayRequests();
        }
    }

    protected function setUp(): void
    {
        // Export actions may set a request timeout. Do not leak it into the
        // next fixture's bootstrap or disposable-schema setup in the CLI suite.
        if (PHP_SAPI === 'cli') {
            set_time_limit(0);
        }
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
