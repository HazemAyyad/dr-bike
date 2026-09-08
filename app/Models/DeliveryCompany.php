<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeliveryCompany extends Model
{
    protected $fillable = [
        'name',
        'code',
        'delivery_type',
        'default_carrier_fee',
        'contact_name',
        'contact_phone',
        'vehicle_number',
        'notes',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'default_carrier_fee' => 'decimal:2',
    ];

    public function operationalType(): string
    {
        $type = strtolower(trim((string) ($this->delivery_type ?: $this->code)));

        return match ($type) {
            'doctor_bike' => 'internal',
            'self' => 'pickup',
            default => $type,
        };
    }
}
