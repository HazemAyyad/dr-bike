<?php

namespace App\Models\OnlineStore;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class OnlineStoreBanner extends Model
{
    protected $fillable = ['image_path', 'title_translations', 'content_translations', 'is_active', 'starts_at', 'ends_at', 'sort_order', 'action_type', 'action_target_id', 'action_url'];

    protected $casts = ['title_translations' => 'array', 'content_translations' => 'array', 'is_active' => 'boolean', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'sort_order' => 'integer', 'action_target_id' => 'integer', 'click_count' => 'integer'];

    protected function startsAt(): Attribute
    {
        return $this->utcScheduleAttribute();
    }

    protected function endsAt(): Attribute
    {
        return $this->utcScheduleAttribute();
    }

    private function utcScheduleAttribute(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value === null ? null : CarbonImmutable::parse($value, 'UTC'),
        );
    }
}
