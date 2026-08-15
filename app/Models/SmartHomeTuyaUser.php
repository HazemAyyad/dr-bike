<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmartHomeTuyaUser extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'tuya_uid',
        'region',
        'raw_metadata',
        'last_login_at',
    ];

    protected $casts = [
        'raw_metadata' => 'array',
        'last_login_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
