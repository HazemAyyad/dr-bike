<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmployeeWifiPresenceLog extends Model
{
    use HasFactory;

    protected $table = 'employee_wifi_presence_logs';

    protected $fillable = [
        'employee_detail_id',
        'user_id',
        'ssid',
        'wifi_connected',
        'network_connected',
        'state',
        'started_at',
        'ended_at',
        'last_seen_at',
        'duration_seconds',
    ];

    protected $casts = [
        'wifi_connected' => 'boolean',
        'network_connected' => 'boolean',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'duration_seconds' => 'integer',
    ];

    public function employee()
    {
        return $this->belongsTo(EmployeeDetail::class, 'employee_detail_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
