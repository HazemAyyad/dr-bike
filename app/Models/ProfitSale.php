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
    ];

    public function paymentBox(): BelongsTo
    {
        return $this->belongsTo(Box::class, 'payment_box_id');
    }

}
