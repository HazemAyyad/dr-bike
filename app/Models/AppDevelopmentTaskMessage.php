<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AppDevelopmentTaskMessage extends Model
{
    protected $fillable = [
        'app_development_task_id',
        'sender_user_id',
        'message_type',
        'body',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(AppDevelopmentTask::class, 'app_development_task_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(AppDevelopmentTaskAttachment::class, 'app_development_task_message_id');
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(AppDevelopmentTaskMessageReaction::class, 'app_development_task_message_id');
    }
}
