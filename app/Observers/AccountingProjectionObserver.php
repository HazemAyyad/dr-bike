<?php

namespace App\Observers;

use App\Services\AccountingProjectionService;
use Illuminate\Database\Eloquent\Model;

class AccountingProjectionObserver
{
    public function saved(Model $model): void
    {
        app(AccountingProjectionService::class)->sync($model);
    }

    public function deleted(Model $model): void
    {
        app(AccountingProjectionService::class)->reverse($model);
    }
}
