<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Note extends Model
{
    use HasFactory, SoftDeletes;

    public const VISIBILITY_PRIVATE = 'private';
    public const VISIBILITY_SHARED = 'shared';
    public const VISIBILITY_PUBLIC = 'public';

    public const VISIBILITIES = [
        self::VISIBILITY_PRIVATE,
        self::VISIBILITY_SHARED,
        self::VISIBILITY_PUBLIC,
    ];

    protected $fillable = [
        'owner_user_id',
        'title',
        'body_json',
        'plain_text',
        'color',
        'visibility',
        'is_pinned',
        'is_archived',
        'reminder_at',
        'reminder_label',
        'reminder_notified_at',
    ];

    protected $casts = [
        'body_json' => 'array',
        'is_pinned' => 'boolean',
        'is_archived' => 'boolean',
        'reminder_at' => 'datetime',
        'reminder_notified_at' => 'datetime',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function collaborators(): HasMany
    {
        return $this->hasMany(NoteCollaborator::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(NoteAttachment::class);
    }
}
