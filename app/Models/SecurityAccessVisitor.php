<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SecurityAccessVisitor extends Model
{
    protected $fillable = [
        'visitor_key',
        'ip_address',
        'user_id',
        'user_name',
        'user_type',
        'device_type',
        'country',
        'country_code',
        'region',
        'city',
        'isp',
        'geo_updated_at',
        'geo_error',
        'user_agent',
        'last_method',
        'last_route',
        'last_status',
        'observations',
        'first_seen_at',
        'last_seen_at',
    ];

    protected $casts = [
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'geo_updated_at' => 'datetime',
    ];
}
