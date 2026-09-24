<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeePermissionAudit extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'actor_user_id',
        'target_employee_id',
        'permission_id',
        'action',
        'metadata',
        'ip_address',
        'created_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];
}
