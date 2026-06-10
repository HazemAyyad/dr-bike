<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SuspendedInstantSale extends Model
{
    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STEP_PRODUCT_PICKER = 'product_picker';

    public const STEP_CHECKOUT = 'checkout';

    protected $fillable = [
        'sales_daily_session_id',
        'created_by_user_id',
        'employee_id',
        'reference_code',
        'current_step',
        'payload',
        'summary_label',
        'total_cost',
        'status',
        'completed_instant_sale_id',
        'completed_by_user_id',
        'cancelled_by_user_id',
        'suspended_at',
        'completed_at',
        'cancelled_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'total_cost' => 'float',
        'suspended_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function salesDailySession(): BelongsTo
    {
        return $this->belongsTo(SalesDailySession::class, 'sales_daily_session_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(EmployeeDetail::class, 'employee_id');
    }

    public function completedInstantSale(): BelongsTo
    {
        return $this->belongsTo(InstantSale::class, 'completed_instant_sale_id');
    }

    public function completedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_user_id');
    }

    public function cancelledByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    public function isSuspended(): bool
    {
        return $this->status === self::STATUS_SUSPENDED;
    }
}
