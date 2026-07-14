<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmployeeSuggestion extends Model
{
    use SoftDeletes;

    public const CATEGORY_SUGGESTION = 'suggestion';
    public const CATEGORY_PROBLEM = 'problem';
    public const CATEGORY_CRITICISM = 'criticism';
    public const CATEGORY_IMPROVEMENT = 'improvement';
    public const CATEGORY_OTHER = 'other';

    public const CATEGORIES = [
        self::CATEGORY_SUGGESTION,
        self::CATEGORY_PROBLEM,
        self::CATEGORY_CRITICISM,
        self::CATEGORY_IMPROVEMENT,
        self::CATEGORY_OTHER,
    ];

    public const STATUS_NEW = 'new';
    public const STATUS_REVIEWED = 'reviewed';
    public const STATUS_CLOSED = 'closed';

    public const STATUSES = [
        self::STATUS_NEW,
        self::STATUS_REVIEWED,
        self::STATUS_CLOSED,
    ];

    protected $fillable = [
        'employee_id',
        'category',
        'title',
        'message',
        'is_anonymous',
        'status',
        'admin_note',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'is_anonymous' => 'boolean',
        'reviewed_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(EmployeeDetail::class, 'employee_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
