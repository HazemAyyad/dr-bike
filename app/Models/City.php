<?php

namespace App\Models;

use App\Enums\SalesOrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class City extends Model
{
    protected $fillable = [
        'name_ar',
        'name_en',
        'shiply_area_code',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function deliveryFees(): HasMany
    {
        return $this->hasMany(CityDeliveryFee::class);
    }

    public function currentDeliveryFee(): ?float
    {
        $fee = $this->deliveryFees()
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->value('fee');

        return $fee !== null ? (float) $fee : null;
    }
}
