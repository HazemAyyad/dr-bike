<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AppDevelopmentTask extends Model
{
    use SoftDeletes;

    public const STATUS_NEW = 'new';
    public const STATUS_REVIEW = 'review';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_WAITING_OWNER = 'waiting_owner';
    public const STATUS_DONE = 'done';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_CANCELED = 'canceled';

    public const STATUSES = [
        self::STATUS_NEW,
        self::STATUS_REVIEW,
        self::STATUS_IN_PROGRESS,
        self::STATUS_WAITING_OWNER,
        self::STATUS_DONE,
        self::STATUS_CLOSED,
        self::STATUS_CANCELED,
    ];

    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    protected $fillable = [
        'parent_id',
        'created_by_user_id',
        'assigned_to_user_id',
        'title',
        'description',
        'status',
        'priority',
        'tags',
        'due_at',
        'manual_progress',
        'started_at',
        'completed_at',
        'closed_at',
    ];

    protected $casts = [
        'manual_progress' => 'integer',
        'tags' => 'array',
        'due_at' => 'date',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function subtasks(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AppDevelopmentTaskMessage::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(AppDevelopmentTaskAttachment::class);
    }

    public function statusLogs(): HasMany
    {
        return $this->hasMany(AppDevelopmentTaskStatusLog::class);
    }
}
