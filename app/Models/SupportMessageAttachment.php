<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportMessageAttachment extends Model
{
    protected $fillable = [
        'support_message_id',
        'disk',
        'path',
        'url',
        'original_name',
        'mime_type',
        'size',
        'attachment_type',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(SupportMessage::class, 'support_message_id');
    }
}
