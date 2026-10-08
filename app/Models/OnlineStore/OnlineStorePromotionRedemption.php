<?php

namespace App\Models\OnlineStore;

use App\Models\Customer;
use App\Models\SalesOrder;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class OnlineStorePromotionRedemption extends Model
{
    protected $fillable = [
        'promotion_id', 'sales_order_id', 'user_id', 'customer_id', 'seller_id',
        'items_count', 'quantity', 'discount_amount', 'used_at',
    ];

    protected $casts = [
        'items_count' => 'integer',
        'quantity' => 'integer',
        'discount_amount' => 'decimal:2',
        'used_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $redemption): void {
            if ((bool) $redemption->customer_id === (bool) $redemption->seller_id) {
                throw new \InvalidArgumentException('A promotion redemption must identify exactly one party.');
            }
        });
    }

    public function promotion()
    {
        return $this->belongsTo(OnlineStorePromotion::class, 'promotion_id');
    }

    public function salesOrder()
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function seller()
    {
        return $this->belongsTo(Seller::class);
    }
}
