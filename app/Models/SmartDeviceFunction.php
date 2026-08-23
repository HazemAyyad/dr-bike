<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmartDeviceFunction extends Model
{
    use HasFactory;

    protected $fillable = [
        'smart_device_id',
        'dp_id',
        'code',
        'display_name',
        'function_type',
        'icon',
        'sort_order',
        'is_visible',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_visible' => 'boolean',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(SmartDevice::class, 'smart_device_id');
    }
}
