<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmartRoomEmployeePermission extends Model
{
    protected $fillable = ['smart_room_id', 'employee_id', 'can_view', 'can_control', 'can_schedule'];

    protected $casts = ['can_view' => 'boolean', 'can_control' => 'boolean', 'can_schedule' => 'boolean'];

    public function room(): BelongsTo
    {
        return $this->belongsTo(SmartRoom::class, 'smart_room_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(EmployeeDetail::class, 'employee_id');
    }
}
