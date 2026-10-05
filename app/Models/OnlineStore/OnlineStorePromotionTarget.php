<?php

namespace App\Models\OnlineStore;

use Illuminate\Database\Eloquent\Model;

class OnlineStorePromotionTarget extends Model
{
    protected $fillable = ['promotion_id', 'target_type', 'target_id'];

    protected static function booted(): void
    {
        static::saving(function (self $target) {
            if (! in_array($target->target_type, ['listing', 'category'], true) || (int) $target->target_id < 1) {
                throw new \InvalidArgumentException('Invalid Online Store promotion target.');
            }
        });
    }

    public function promotion()
    {
        return $this->belongsTo(OnlineStorePromotion::class, 'promotion_id');
    }
}
