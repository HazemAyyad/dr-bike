<?php

namespace App\Models\OnlineStore;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OnlineStoreAuditEvent extends Model
{
    public const ENTITY_TYPES = [
        'listing', 'promotion', 'coupon', 'popup_campaign', 'settings', 'account_link', 'credit_policy', 'review', 'pricing',
    ];

    public const ACTIONS = [
        'created', 'updated', 'linked', 'approved', 'suspended', 'activated', 'deactivated',
        'published', 'hidden', 'status_changed', 'moderated', 'previewed', 'deleted',
    ];

    protected $fillable = [
        'actor_user_id', 'action', 'entity_type', 'entity_id', 'before_values',
        'after_values', 'request_id', 'ip_address', 'occurred_at',
    ];

    protected $casts = [
        'before_values' => 'array',
        'after_values' => 'array',
        'occurred_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $event): void {
            if (! in_array($event->entity_type, self::ENTITY_TYPES, true)
                || ! in_array($event->action, self::ACTIONS, true)) {
                throw new \InvalidArgumentException('Unsupported Online Store audit entity or action.');
            }
        });
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
