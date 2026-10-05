<?php

namespace App\Models\OnlineStore;

use Illuminate\Database\Eloquent\Model;

class OnlineStoreCouponTarget extends Model
{
    protected $fillable = ['coupon_id', 'target_type', 'target_id'];

    protected static function booted(): void
    {
        static::saving(function (self $target) {
            if (! in_array($target->target_type, ['listing', 'category'], true) || (int) $target->target_id < 1) {
                throw new \InvalidArgumentException('Invalid Online Store coupon target.');
            }
        });
    }

    public function coupon()
    {
        return $this->belongsTo(OnlineStoreCoupon::class, 'coupon_id');
    }
}
