<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PurchaseReturn extends Model
{
    use HasFactory;
    protected $table = 'purchase_returns';

    protected $fillable = [
        'return_id', 'bill_id',
        'bill_item_id',
        'product_id',
        'size_id',
        'size_color_id',
        'price', 'line_total',
        'quantity',
        'cost_total',
        'reason', 'note', 'notes',
    ];

    protected $casts = [
        'price' => 'float', 'line_total' => 'float',
        'quantity' => 'float',
        'cost_total' => 'float',
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

    public function billItem()
    {
        return $this->belongsTo(BillItem::class, 'bill_item_id');
    }

    public function bill()
    {
        return $this->belongsTo(Bill::class, 'bill_id');
    }

    public function size()
    {
        return $this->belongsTo(Size::class, 'size_id');
    }

    public function sizeColor()
    {
        return $this->belongsTo(SizeColor::class, 'size_color_id');
    }
}
