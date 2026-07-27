<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaintenancePayment extends Model
{
    protected $fillable = [
        'maintenance_id',
        'maintenance_daily_session_id',
        'box_id',
        'instant_sale_id',
        'created_by',
        'method',
        'amount',
        'currency',
        'note',
    ];

    protected $casts = [
        'amount' => 'float',
    ];

    public function maintenance(): BelongsTo
    {
        return $this->belongsTo(Maintenance::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(MaintenanceDailySession::class, 'maintenance_daily_session_id');
    }

    public function box(): BelongsTo
    {
        return $this->belongsTo(Box::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
