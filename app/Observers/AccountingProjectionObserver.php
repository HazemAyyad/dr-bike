<?php

namespace App\Observers;

use App\Services\AccountingProjectionService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Database\Eloquent\Model;

class AccountingProjectionObserver implements ShouldHandleEventsAfterCommit
{
    public function saved(Model $model): void
    {
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
