<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PartnerAddress extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'addressable_type', 'addressable_id', 'label', 'city_id', 'shiply_city_id',
        'shiply_village_id', 'shiply_city_name', 'shiply_village_name', 'street_address',
        'phone', 'latitude', 'longitude', 'delivery_notes', 'is_default', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'latitude' => 'float',
        'longitude' => 'float',
    ];

    public function addressable(): MorphTo
    {
        return $this->morphTo();
    }
}
