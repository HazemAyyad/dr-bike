<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserAppVersion extends Model
{
    protected $fillable = [
        'user_id',
        'app',
        'platform',
        'device_key',
        'device_name',
        'version',
        'build',
        'fcm_token',
        'last_seen_at',
    ];

    protected $casts = [
        'build' => 'integer',
        'last_seen_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
