<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmartHomeEventLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'smart_home_id',
        'event',
        'success',
        'error_code',
        'message',
        'context',
    ];

    protected $casts = [
        'success' => 'boolean',
        'context' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function home(): BelongsTo
    {
        return $this->belongsTo(SmartHome::class, 'smart_home_id');
    }
}
