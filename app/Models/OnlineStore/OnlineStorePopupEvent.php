<?php

namespace App\Models\OnlineStore;

use App\Models\Store\StoreUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OnlineStorePopupEvent extends Model
{
    public const TYPES = ['impression', 'click', 'dismiss'];

    protected $fillable = [
        'campaign_id', 'user_id', 'visitor_hash', 'session_hash', 'event_type', 'occurred_at',
    ];

    protected $casts = ['occurred_at' => 'datetime'];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(OnlineStorePopupCampaign::class, 'campaign_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(StoreUser::class, 'user_id');
    }
}
