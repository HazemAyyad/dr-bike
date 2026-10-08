<?php

namespace App\Models\OnlineStore;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class OnlineStorePromotion extends Model
{
    protected $fillable = ['name', 'description_translations', 'discount_type', 'discount_value', 'applies_to', 'scope', 'starts_at', 'ends_at', 'is_active', 'priority'];

    protected $casts = ['description_translations' => 'array', 'discount_value' => 'decimal:2', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'is_active' => 'boolean', 'priority' => 'integer'];

    public function targets()
    {
        return $this->hasMany(OnlineStorePromotionTarget::class, 'promotion_id');
    }

    public function redemptions()
    {
        return $this->hasMany(OnlineStorePromotionRedemption::class, 'promotion_id');
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
