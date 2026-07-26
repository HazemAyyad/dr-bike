<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NoteCollaborator extends Model
{
    public const PERMISSION_VIEW = 'view';
    public const PERMISSION_EDIT = 'edit';

    public const PERMISSIONS = [
        self::PERMISSION_VIEW,
        self::PERMISSION_EDIT,
    ];

    protected $fillable = [
        'note_id',
        'user_id',
        'permission',
    ];

    public function note(): BelongsTo
    {
        return $this->belongsTo(Note::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
