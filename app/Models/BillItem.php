<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BillItem extends Model
{
    use HasFactory;
    protected $table = 'bill_items';
    protected $fillable = [
        'quantity',
        'ordered_quantity',
        'received_owned_quantity',
        'custody_quantity',
        'damaged_quantity',
        'mismatched_quantity',
        'product_id',
        'size_id',
        'size_color_id',
        'bill_id',
        'status',
        'price',
        'final_unit_price',
        'extra_amount',
        'missing_amount',
        'not_compatible_amount',
        'not_compatible_description',
    ];

    protected $casts = [
        'quantity' => 'float',
        'ordered_quantity' => 'float',
        'received_owned_quantity' => 'float',
        'custody_quantity' => 'float',
        'damaged_quantity' => 'float',
        'mismatched_quantity' => 'float',
        'price' => 'float',
        'final_unit_price' => 'float',
    ];

    public function bill(){
        return $this->belongsTo(Bill::class,'bill_id');
    }

    public function product(){
        return $this->belongsTo(Product::class);
    }

    public function size()
    {
        return $this->belongsTo(Size::class, 'size_id');
    }

    public function sizeColor()
    {
        return $this->belongsTo(SizeColor::class, 'size_color_id');
    }

    public function receiptItems(){
        return $this->hasMany(PurchaseReceiptItem::class);
    }

    public function amanatStocks(){
        return $this->hasMany(PurchaseAmanatStock::class);
    }

    public function purchaseReturnItems(){
        return $this->hasMany(PurchaseReturn::class, 'bill_item_id');
    }
}
