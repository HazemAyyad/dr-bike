<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesOrderMedia extends Model
{
    protected $table = 'sales_order_media';

    protected $fillable = [
        'sales_order_id',
        'status_at_upload',
        'category',
        'type',
        'path',
        'mime',
        'size_bytes',
        'uploaded_by',
    ];

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }
}
