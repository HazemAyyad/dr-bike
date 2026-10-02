<?php

namespace App\Models\OnlineStore;

use App\Models\Customer;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class OnlineStoreAccountLink extends Model
{
    protected $fillable = ['user_id', 'customer_id', 'seller_id', 'role', 'account_source', 'status'];

    protected $casts = ['verified_at' => 'datetime'];

    protected static function booted(): void
    {
        static::saving(function (self $link) {
            $matches = ($link->role === 'customer' && $link->customer_id && ! $link->seller_id)
                || ($link->role === 'seller' && $link->seller_id && ! $link->customer_id);
            if (! $matches) {
                throw new \InvalidArgumentException('An Online Store account link must have exactly one role-matching party.');
            }
            if (! in_array($link->account_source, ['store_app', 'admin_app', 'import'], true)
                || ! in_array($link->status, ['pending', 'active', 'suspended'], true)) {
                throw new \InvalidArgumentException('Invalid Online Store account link source or status.');
            }
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function seller()
    {
        return $this->belongsTo(Seller::class);
    }

    public function linkedBy()
    {
        return $this->belongsTo(User::class, 'linked_by');
    }

    public function verifiedBy()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function party(): Customer|Seller|null
    {
        return $this->role === 'customer' ? $this->customer : $this->seller;
    }
}
