<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PurchaseReturn extends Model
{
    use HasFactory;
       protected $table = 'purchase_returns';

    protected $fillable = [
        'return_id',
        'product_id',
        'price',
        'quantity',
    ];

    /**
     * A purchase return belongs to a return.
     */
    public function return()
    {
        return $this->belongsTo(ReturnModel::class, 'return_id');
    }

    /**
     * A purchase return belongs to a product.
     */
    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
