<?php

namespace App\Models\OnlineStore;

use App\Models\SalesOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class OnlineStoreLegacyCheckoutAttempt extends Model
{
    protected $fillable = ['origin_user_id', 'request_fingerprint', 'sales_order_id', 'expires_at'];

    protected $casts = ['expires_at' => 'datetime'];

    public function originUser()
    {
        return $this->belongsTo(User::class, 'origin_user_id');
    }

    public function salesOrder()
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function scopeExpired(Builder $query, $at = null): Builder
    {
        return $query->where('expires_at', '<=', $at ?? now());
    }
}
