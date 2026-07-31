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

    public const TYPE_ASSEMBLY_COMPONENT = 'assembly_component';

    public const TYPE_ASSEMBLY_OUTPUT = 'assembly_output';

    public const TYPE_DISASSEMBLY_COMPONENT = 'disassembly_component';

    public const TYPE_DISASSEMBLY_OUTPUT = 'disassembly_output';

    public const TYPE_PRICE_UPDATE = 'price_update';

    public const TYPE_PRODUCT_UPDATE = 'product_update';

    protected $fillable = [
        'product_id',
        'size_id',
        'size_color_id',
        'type',
        'quantity',
        'stock_before',
        'stock_after',
        'unit_cost',
        'total_cost',
        'reference_type',
        'reference_id',
        'note',
        'created_by',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'stock_before' => 'integer',
        'stock_after' => 'integer',
        'unit_cost' => 'float',
        'total_cost' => 'float',
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
