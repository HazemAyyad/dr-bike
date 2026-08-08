<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MetaCatalogProductSync extends Model
{
    protected $fillable = [
        'whatsapp_account_id', 'catalog_id', 'product_id', 'variant_id',
        'meta_catalog_item_id', 'meta_catalog_retailer_id', 'sync_status',
        'last_synced_at', 'last_error', 'payload',
    ];

    protected $casts = [
        'last_synced_at' => 'datetime',
        'payload' => 'array',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variant()
    {
        return $this->belongsTo(SizeColor::class, 'variant_id');
    }

    public function whatsappAccount()
    {
        return $this->belongsTo(WhatsAppAccount::class, 'whatsapp_account_id');
    }
}
