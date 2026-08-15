<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SmartRoom extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'smart_home_id',
        'tuya_room_id',
        'name',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function home(): BelongsTo
    {
        return $this->belongsTo(SmartHome::class, 'smart_home_id');
    }

    public function devices(): HasMany
    {
        return $this->hasMany(SmartDevice::class);
    }
}
