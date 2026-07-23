<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppDevelopmentTaskMessageReaction extends Model
{
    protected $fillable = [
        'app_development_task_message_id',
        'user_id',
        'reaction',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(AppDevelopmentTaskMessage::class, 'app_development_task_message_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
