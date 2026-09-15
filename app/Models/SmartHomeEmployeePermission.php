<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmartHomeEmployeePermission extends Model
{
    protected $fillable = ['smart_home_id', 'employee_id', 'can_view', 'can_control', 'can_schedule'];

    protected $casts = ['can_view' => 'boolean', 'can_control' => 'boolean', 'can_schedule' => 'boolean'];

    public function home(): BelongsTo
    {
        return $this->belongsTo(SmartHome::class, 'smart_home_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(EmployeeDetail::class, 'employee_id');
    }
}
