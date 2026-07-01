<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MetaCatalogSyncLog extends Model
{
    protected $fillable = [
        'product_id', 'variant_id', 'action', 'status', 'meta_catalog_item_id',
        'retailer_id', 'request_payload', 'response_payload', 'error_message',
    ];

    protected $casts = [
        'request_payload' => 'array',
        'response_payload' => 'array',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variant()
    {
        return $this->belongsTo(SizeColor::class, 'variant_id');
    }
}
