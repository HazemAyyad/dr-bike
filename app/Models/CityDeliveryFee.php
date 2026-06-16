<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CityDeliveryFee extends Model
{
    protected $fillable = [
        'city_id',
        'fee',
        'effective_from',
    ];

    protected $casts = [
        'fee' => 'float',
        'effective_from' => 'date',
    ];

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }
}
