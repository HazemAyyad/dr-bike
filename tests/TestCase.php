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
