<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MetaCatalogProductSet extends Model
{
    protected $fillable = [
        'whatsapp_account_id', 'catalog_id', 'source_type', 'source_id', 'parent_source_id', 'name',
        'meta_product_set_id', 'filter_field', 'filter_value', 'filter_payload',
        'sync_status', 'last_synced_at', 'last_error',
    ];

    protected $casts = [
        'filter_payload' => 'array',
        'last_synced_at' => 'datetime',
    ];

    public function whatsappAccount()
    {
        return $this->belongsTo(WhatsAppAccount::class, 'whatsapp_account_id');
    }
}
