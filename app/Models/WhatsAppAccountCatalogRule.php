<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppAccountCatalogRule extends Model
{
    protected $table = 'whatsapp_account_catalog_rules';

    protected $fillable = ['whatsapp_account_id', 'source_type', 'source_id', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];
}
