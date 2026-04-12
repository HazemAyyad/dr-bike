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
    ];

    protected $casts = [
    'files' => 'array',
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
}
