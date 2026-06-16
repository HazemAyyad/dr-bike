<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesReturn extends Model
{
    protected $fillable = [
        'sales_order_id',
        'return_type',
        'instant_sale_id',
        'total_amount',
        'note',
        'created_by',
    ];

    protected $casts = [
        'total_amount' => 'float',
    ];

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesReturnItem::class);
    }

    public function instantSale(): BelongsTo
    {
        return $this->belongsTo(InstantSale::class);
    }
}
