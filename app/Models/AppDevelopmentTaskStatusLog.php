<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppDevelopmentTaskStatusLog extends Model
{
    protected $fillable = [
        'app_development_task_id',
        'changed_by_user_id',
        'old_status',
        'new_status',
        'note',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(AppDevelopmentTask::class, 'app_development_task_id');
    }

    public function changer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
