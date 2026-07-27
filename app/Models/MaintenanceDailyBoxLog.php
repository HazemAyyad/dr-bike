<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaintenanceDailyBoxLog extends Model
{
    protected $fillable = [
        'session_id',
        'box_id',
        'maintenance_id',
        'instant_sale_id',
        'user_id',
        'actor_name',
        'type',
        'payment_method',
        'affects_cash_balance',
        'amount',
        'box_balance_before',
        'box_balance_after',
        'description',
        'note',
    ];

    protected $casts = [
        'amount' => 'float',
        'box_balance_before' => 'float',
        'box_balance_after' => 'float',
        'affects_cash_balance' => 'boolean',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(MaintenanceDailySession::class, 'session_id');
    }

    public function box(): BelongsTo
    {
        return $this->belongsTo(Box::class);
    }

    public function maintenance(): BelongsTo
    {
        return $this->belongsTo(Maintenance::class);
    }

    public function instantSale(): BelongsTo
    {
        return $this->belongsTo(InstantSale::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
