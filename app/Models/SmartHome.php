<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SmartHome extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_ARCHIVED = 'archived';
    public const TYPE_HOME = 'home';
    public const TYPE_COMPANY = 'company';

    protected $fillable = [
        'user_id',
        'tuya_home_id',
        'name',
        'type',
        'latitude',
        'longitude',
        'geo_name',
        'is_default',
        'status',
        'raw_metadata',
    ];

    protected $casts = [
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'is_default' => 'boolean',
        'raw_metadata' => 'array',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(SmartRoom::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(SmartDevice::class);
    }

    public function scenes(): HasMany
    {
        return $this->hasMany(SmartScene::class);
    }
}
