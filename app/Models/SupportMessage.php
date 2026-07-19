<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportMessage extends Model
{
    public const SENDER_EMPLOYEE = 'employee';
    public const SENDER_SUPPORT = 'support';
    public const SENDER_SYSTEM = 'system';

    public const TYPE_TEXT = 'text';
    public const TYPE_IMAGE = 'image';
    public const TYPE_VIDEO = 'video';
    public const TYPE_AUDIO = 'audio';
    public const TYPE_DOCUMENT = 'document';
    public const TYPE_SYSTEM = 'system';

    protected $fillable = [
        'support_conversation_id',
        'sender_user_id',
        'sender_employee_id',
        'sender_type',
        'message_type',
        'body',
        'read_at',
    ];

    protected $casts = [
        'read_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(SupportConversation::class, 'support_conversation_id');
    }

    public function senderUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    public function senderEmployee(): BelongsTo
    {
        return $this->belongsTo(EmployeeDetail::class, 'sender_employee_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(SupportMessageAttachment::class, 'support_message_id');
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(SupportMessageReaction::class, 'support_message_id');
    }
}
