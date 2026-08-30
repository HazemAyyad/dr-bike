<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationPolicyAudit extends Model
{
    protected $fillable = [
        'auditable_type', 'auditable_id', 'action', 'before', 'after',
        'user_id', 'ip_address',
    ];

    protected $casts = ['before' => 'array', 'after' => 'array'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
