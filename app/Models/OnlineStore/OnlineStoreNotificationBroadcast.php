<?php

namespace App\Models\OnlineStore;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OnlineStoreNotificationBroadcast extends Model
{
    public const AUDIENCES = ['all', 'guests', 'new_users', 'no_orders', 'customers'];

    public const DESTINATIONS = ['home', 'listing', 'category', 'url', 'none'];

    protected $fillable = [
        'title_translations', 'body_translations', 'audience_type', 'audience_days',
        'destination_type', 'destination_id', 'destination_url', 'recipient_count',
        'sent_count', 'push_count', 'skipped_count', 'popup_campaign_id', 'created_by', 'sent_at',
    ];

    protected $casts = [
        'title_translations' => 'array',
        'body_translations' => 'array',
        'audience_days' => 'integer',
        'destination_id' => 'integer',
        'recipient_count' => 'integer',
        'sent_count' => 'integer',
        'push_count' => 'integer',
        'skipped_count' => 'integer',
        'sent_at' => 'datetime',
    ];

    public function popupCampaign(): BelongsTo
    {
        return $this->belongsTo(OnlineStorePopupCampaign::class, 'popup_campaign_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
