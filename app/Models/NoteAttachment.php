<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NoteAttachment extends Model
{
    public const TYPE_IMAGE = 'image';
    public const TYPE_VIDEO = 'video';
    public const TYPE_AUDIO = 'audio';
    public const TYPE_DRAWING = 'drawing';
    public const TYPE_FILE = 'file';

    public const TYPES = [
        self::TYPE_IMAGE,
        self::TYPE_VIDEO,
        self::TYPE_AUDIO,
        self::TYPE_DRAWING,
        self::TYPE_FILE,
    ];

    protected $fillable = [
        'note_id',
        'uploaded_by_user_id',
        'disk',
        'path',
        'url',
        'original_name',
        'mime_type',
        'size',
        'attachment_type',
    ];

    public function note(): BelongsTo
    {
        return $this->belongsTo(Note::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
