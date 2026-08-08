<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsAppAccount extends Model
{
    protected $table = 'whatsapp_accounts';

    protected $fillable = [
        'name', 'display_phone_number', 'phone_number_id', 'waba_id', 'catalog_id',
        'access_token_env_key', 'is_active', 'is_verified', 'sort_order', 'settings',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_verified' => 'boolean',
        'settings' => 'array',
    ];

    public function catalogRules(): HasMany
    {
        return $this->hasMany(WhatsAppAccountCatalogRule::class, 'whatsapp_account_id');
    }

    public function productSyncs(): HasMany
    {
        return $this->hasMany(MetaCatalogProductSync::class, 'whatsapp_account_id');
    }

    public function accessToken(): ?string
    {
        $key = $this->access_token_env_key ?: 'WHATSAPP_ACCESS_TOKEN';
        return env($key) ?: config('whatsapp.access_token');
    }
}
