<?php

namespace App\Models\OnlineStore;

use App\Models\User;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class OnlineStoreCoupon extends Model
{
    protected $fillable = ['code', 'discount_type', 'discount_value', 'starts_at', 'ends_at', 'minimum_order', 'total_usage_limit', 'per_user_usage_limit', 'eligible_account_type', 'applies_to', 'is_active', 'scope'];

    protected $casts = ['discount_value' => 'decimal:2', 'minimum_order' => 'decimal:2', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'is_active' => 'boolean', 'total_usage_limit' => 'integer', 'per_user_usage_limit' => 'integer'];

    protected function code(): Attribute
    {
        return Attribute::make(set: fn ($value) => mb_strtoupper(trim((string) $value)));
    }

    public function targets()
    {
        return $this->hasMany(OnlineStoreCouponTarget::class, 'coupon_id');
    }

    public function redemptions()
    {
        return $this->hasMany(OnlineStoreCouponRedemption::class, 'coupon_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
