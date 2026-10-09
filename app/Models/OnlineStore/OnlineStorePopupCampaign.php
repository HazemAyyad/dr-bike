<?php

namespace App\Models\OnlineStore;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OnlineStorePopupCampaign extends Model
{
    public const THEMES = ['brand', 'success', 'warm', 'dark'];

    public const AUDIENCES = ['all', 'guests', 'registered', 'new_users', 'no_orders', 'customers'];

    public const FREQUENCIES = ['once', 'once_per_session', 'always'];

    public const ACTION_TYPES = ['listing', 'category', 'promotion', 'coupon', 'url', 'none'];

    protected $fillable = [
        'name', 'image_path', 'title_translations', 'content_translations', 'button_translations',
        'theme', 'audience_type', 'audience_days', 'display_frequency', 'action_type',
        'action_target_id', 'action_url', 'is_active', 'starts_at', 'ends_at', 'priority',
    ];

    protected $casts = [
        'title_translations' => 'array',
        'content_translations' => 'array',
        'button_translations' => 'array',
        'audience_days' => 'integer',
        'action_target_id' => 'integer',
        'is_active' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'priority' => 'integer',
    ];

    public function events(): HasMany
    {
        return $this->hasMany(OnlineStorePopupEvent::class, 'campaign_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
