<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProfitSale extends Model
{
    use HasFactory;
    protected $table = 'profit_sales';
    protected $fillable = [
        'total_cost',
        'notes',
        'image_path',
        'video_path',
        'buyer_type',
        'customer_id',
        'seller_id',
        'buyer_name',
        'payment_box_id',
        'payment_box_name',
        'payment_box_value',
        'status',
        'cancelled_at',
    ];

    protected $casts = [
        'cancelled_at' => 'datetime',
    ];

    public function paymentBox(): BelongsTo
    {
        return $this->belongsTo(Box::class, 'payment_box_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class, 'seller_id');
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled' || $this->cancelled_at !== null;
    }

}
