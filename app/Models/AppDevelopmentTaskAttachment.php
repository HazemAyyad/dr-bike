<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppDevelopmentTaskAttachment extends Model
{
    protected $fillable = [
        'app_development_task_id',
        'app_development_task_message_id',
        'disk',
        'path',
        'url',
        'original_name',
        'mime_type',
        'size',
        'attachment_type',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(AppDevelopmentTask::class, 'app_development_task_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(AppDevelopmentTaskMessage::class, 'app_development_task_message_id');
    }
}
