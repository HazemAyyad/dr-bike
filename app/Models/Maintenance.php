<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Maintenance extends Model
{
    use HasFactory;

    protected $table = 'maintenance';

    protected $fillable = [
        'customer_id',
        'description',
        'status',
        'receipt_date',
        'end_date',
        'receipt_time',
        'files',
        'seller_id',
        'labor_cost',
        'discount',
        'invoice_total',
        'paid_amount',
        'payment_box_id',
        'instant_sale_id',
    ];

    protected $casts = [
        'files' => 'array',
        'labor_cost' => 'float',
        'discount' => 'float',
        'invoice_total' => 'float',
        'paid_amount' => 'float',
    ];


    /**
     * Get the customer associated with the maintenance.
     */
    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
    public function seller()
    {
        return $this->belongsTo(Seller::class);
    }

    public function products()
    {
        return $this->hasMany(MaintenanceProduct::class);
    }

    public function instantSale()
    {
        return $this->belongsTo(InstantSale::class);
    }
}
