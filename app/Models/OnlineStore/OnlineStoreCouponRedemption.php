<?php

namespace App\Models\OnlineStore;

use App\Models\Customer;
use App\Models\SalesOrder;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class OnlineStoreCouponRedemption extends Model
{
    protected $fillable = ['coupon_id', 'sales_order_id', 'user_id', 'customer_id', 'seller_id', 'discount_amount', 'status', 'applied_at', 'released_at'];

    protected $casts = ['discount_amount' => 'decimal:2', 'applied_at' => 'datetime', 'released_at' => 'datetime'];

    protected static function booted(): void
    {
        static::saving(function (self $redemption) {
            if ((bool) $redemption->customer_id === (bool) $redemption->seller_id) {
                throw new \InvalidArgumentException('A coupon redemption must identify exactly one party.');
            }
            if (! in_array($redemption->status, ['reserved', 'applied', 'released'], true)) {
                throw new \InvalidArgumentException('Invalid coupon redemption status.');
            }
        });
    }

    public function coupon()
    {
        return $this->belongsTo(OnlineStoreCoupon::class, 'coupon_id');
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
