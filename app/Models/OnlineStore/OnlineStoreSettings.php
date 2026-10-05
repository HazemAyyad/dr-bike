<?php

namespace App\Models\OnlineStore;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OnlineStoreSettings extends Model
{
    public const SINGLETON_ID = 1;

    public const OUT_OF_STOCK_BEHAVIOR = 'visible_non_purchasable';

    protected $table = 'online_store_settings';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = [
        'store_enabled', 'maintenance_mode', 'checkout_enabled', 'cod_enabled',
        'guest_browsing_enabled', 'minimum_order', 'support_phone', 'whatsapp',
        'enabled_languages', 'cancellation_policy_translations', 'return_policy_translations',
        'warranty_policy_translations', 'terms_translations', 'out_of_stock_behavior',
        'low_stock_threshold', 'updated_by',
    ];

    protected $casts = [
        'store_enabled' => 'boolean',
        'maintenance_mode' => 'boolean',
        'checkout_enabled' => 'boolean',
        'cod_enabled' => 'boolean',
        'guest_browsing_enabled' => 'boolean',
        'minimum_order' => 'decimal:2',
        'enabled_languages' => 'array',
        'cancellation_policy_translations' => 'array',
        'return_policy_translations' => 'array',
        'warranty_policy_translations' => 'array',
        'terms_translations' => 'array',
        'low_stock_threshold' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $settings): void {
            if ((int) $settings->getKey() !== self::SINGLETON_ID) {
                throw new \LogicException('Online Store settings must use the singleton row.');
            }

            if ($settings->out_of_stock_behavior !== self::OUT_OF_STOCK_BEHAVIOR) {
                throw new \LogicException('Online Store V1 out-of-stock behavior is fixed.');
            }
        });
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
