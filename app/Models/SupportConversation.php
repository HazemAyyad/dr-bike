<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SupportConversation extends Model
{
    use SoftDeletes;

    public const SOURCE_EMPLOYEE = 'employee';

    public const SOURCE_ONLINE_STORE = 'online_store';

    public const SOURCES = [self::SOURCE_EMPLOYEE, self::SOURCE_ONLINE_STORE];

    public const CONTEXT_GENERAL = 'general';

    public const CONTEXT_PRODUCT = 'product';

    public const CONTEXT_TYPES = [self::CONTEXT_GENERAL, self::CONTEXT_PRODUCT];

    public const STATUS_OPEN = 'open';

    public const STATUS_PENDING = 'pending';

    public const STATUS_CLOSED = 'closed';

    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_PENDING,
        self::STATUS_CLOSED,
    ];

    public const PRIORITY_LOW = 'low';

    public const PRIORITY_NORMAL = 'normal';

    public const PRIORITY_HIGH = 'high';

    public const PRIORITY_URGENT = 'urgent';

    public const PRIORITIES = [
        self::PRIORITY_LOW,
        self::PRIORITY_NORMAL,
        self::PRIORITY_HIGH,
        self::PRIORITY_URGENT,
    ];

    protected $fillable = [
        'source',
        'employee_id',
        'created_by_user_id',
        'requester_user_id',
        'assigned_to_user_id',
        'employee_suggestion_id',
        'context_type',
        'online_store_listing_id',
        'context_snapshot',
        'subject',
        'status',
        'priority',
        'last_message',
        'last_message_at',
        'employee_unread_count',
        'requester_unread_count',
        'support_unread_count',
        'first_support_response_at',
        'last_requester_message_at',
        'last_support_message_at',
        'closed_by_user_id',
        'closed_at',
    ];

    protected $casts = [
        'context_snapshot' => 'array',
        'last_message_at' => 'datetime',
        'first_support_response_at' => 'datetime',
        'last_requester_message_at' => 'datetime',
        'last_support_message_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(EmployeeDetail::class, 'employee_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_user_id');
    }

    public function onlineStoreListing(): BelongsTo
    {
        return $this->belongsTo(OnlineStore\OnlineStoreListing::class, 'online_store_listing_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function suggestion(): BelongsTo
    {
        return $this->belongsTo(EmployeeSuggestion::class, 'employee_suggestion_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class, 'support_conversation_id');
    }
}
