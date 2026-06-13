<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductStockMovement extends Model
{
    use HasFactory;

    public const TYPE_PURCHASE = 'purchase';

    public const TYPE_BILL_QUANTITY = 'bill_quantity';

    public const TYPE_SALE = 'sale';

    public const TYPE_SALE_CANCEL = 'sale_cancel';

    public const TYPE_DESTRUCTION = 'destruction';

    public const TYPE_RETURN = 'return';

    public const TYPE_MANUAL_ADD = 'manual_add';

    public const TYPE_MANUAL_SET = 'manual_set';

    public const TYPE_IMPORT = 'import';

    protected $fillable = [
        'product_id',
        'size_id',
        'size_color_id',
        'type',
        'quantity',
        'stock_before',
        'stock_after',
        'reference_type',
        'reference_id',
        'note',
        'created_by',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'stock_before' => 'integer',
        'stock_after' => 'integer',
    ];

    public function product()
    {
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

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
