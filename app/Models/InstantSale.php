<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InstantSale extends Model
{
    use HasFactory;

    protected $table = 'instant_sales';

    protected $fillable = [
        'product_id',
        'offer_package_id',
        'parent_id',
        'total_cost',
        'cost',
        'notes',
        'quantity',
        'discount',
        'project_id',
        'type',
        'buyer_type',
        'buyer_id',
        'buyer_name',
        'buyer_phone',
        'buyer_address',
        'payment_box_id',
        'payment_box_name',
        'payment_box_value',
        'status',
        'cancelled_at',
        'stock_restored',
    ];

    protected $casts = [
        'payment_box_value' => 'float',
        'cancelled_at' => 'datetime',
        'stock_restored' => 'boolean',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function offerPackage()
    {
        return $this->belongsTo(OfferPackage::class);
    }

    public function subProducts()
    {
        return $this->hasMany(InstantSale::class, 'parent_id');
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    /** Buyer snapshot references customers.id (traders and retail customers share this table). */
    public function buyerCustomer()
    {
        return $this->belongsTo(Customer::class, 'buyer_id');
    }

    public function paymentBox()
    {
        return $this->belongsTo(Box::class, 'payment_box_id');
    }

    public function isCancelled(): bool
    {
        if ($this->status === 'cancelled') {
            return true;
        }

        return $this->cancelled_at !== null;
    }
}
