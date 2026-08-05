<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class AttendanceOvertimeRule extends Model
{
    protected $fillable = [
        'grace_minutes',
        'effective_from',
        'created_by',
    ];

    protected $casts = [
        'grace_minutes' => 'integer',
        'effective_from' => 'date',
    ];

    public static function graceMinutesForDate(string|Carbon|null $date = null): int
    {
        $dateStr = $date instanceof Carbon
            ? $date->toDateString()
            : Carbon::parse($date ?? now())->toDateString();

        $rule = static::query()
            ->whereDate('effective_from', '<=', $dateStr)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();

        return max(0, min(240, (int) ($rule?->grace_minutes ?? 15)));
    }

    public static function currentRule(): ?self
    {
        return static::query()
            ->whereDate('effective_from', '<=', now()->toDateString())
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }
}
