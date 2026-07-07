<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MaintenanceActivityLog extends Model
{
    protected $fillable = [
        'maintenance_id',
        'user_id',
        'actor_name',
        'actor_type',
        'action',
        'title',
        'description',
        'old_status',
        'new_status',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function maintenance()
    {
        return $this->belongsTo(Maintenance::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
