<?php

namespace Tests\Support;

use Illuminate\Foundation\Testing\DatabaseTransactionsManager;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

trait StoreTestSchema
{
    /** One disposable schema per process; each fixture rolls back independently. */
    protected function refreshStoreSchema(): void
    {
        $this->requireDisposableDatabase();
        if (! RefreshDatabaseState::$migrated) {
            if (! filter_var(env('ONLINE_STORE_PREMIGRATED_SCHEMA_READY', false), FILTER_VALIDATE_BOOL)) {
                Artisan::call('migrate:fresh', ['--force' => true]);
            }
            RefreshDatabaseState::$migrated = true;
        }

        $connection = DB::connection();
        $manager = new DatabaseTransactionsManager([config('database.default')]);
        $this->app->instance('db.transactions', $manager);
        $connection->setTransactionManager($manager);
        $dispatcher = $connection->getEventDispatcher();
        $connection->unsetEventDispatcher();
        $connection->beginTransaction();
        $connection->setEventDispatcher($dispatcher);
        $this->beforeApplicationDestroyed(function () use ($connection): void {
            $dispatcher = $connection->getEventDispatcher();
            $connection->unsetEventDispatcher();
            $connection->rollBack();
            $connection->setEventDispatcher($dispatcher);
            $connection->disconnect();
        });
    }
}
