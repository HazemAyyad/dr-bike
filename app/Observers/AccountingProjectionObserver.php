<?php

namespace App\Observers;

use App\Models\Asset;
use App\Services\AccountingProjectionService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Database\Eloquent\Model;

class AccountingProjectionObserver implements ShouldHandleEventsAfterCommit
{
    public function saved(Model $model): void
    {
        if ($model instanceof Asset
            && ! $model->wasRecentlyCreated
            && ! $model->wasChanged(['price', 'acquired_at', 'box_id', 'currency'])) {
            return;
        }

        $fresh = $model->newQuery()->find($model->getKey());
        if ($fresh) {
            app(AccountingProjectionService::class)->sync($fresh);
        }
    }

    public function deleted(Model $model): void
    {
        app(AccountingProjectionService::class)->reverse($model);
    }
}
