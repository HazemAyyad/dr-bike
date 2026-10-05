<?php

namespace App\Models\OnlineStore;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OnlineStoreCreditPolicy extends Model
{
    public const CURRENCIES = ['ILS', 'USD', 'JOD'];

    protected $attributes = [
        'is_eligible' => false,
        'currency' => 'ILS',
    ];

    protected $fillable = [
        'account_link_id', 'is_eligible', 'credit_limit', 'currency', 'approved_by',
        'approved_at', 'expires_at', 'notes', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'is_eligible' => 'boolean',
        'credit_limit' => 'decimal:2',
        'approved_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $policy): void {
            if (! $policy->account_link_id || ! in_array($policy->currency, self::CURRENCIES, true)) {
                throw new \InvalidArgumentException('A Store credit policy requires a linked account and supported currency.');
            }
            if ($policy->credit_limit !== null && (float) $policy->credit_limit < 0) {
                throw new \InvalidArgumentException('A Store credit limit cannot be negative.');
            }
        });
    }

    public function isApprovedAt(CarbonInterface $at): bool
    {
        return $this->is_eligible
            && $this->approved_at !== null
            && ($this->expires_at === null || $this->expires_at->greaterThan($at));
    }

    public static function normalizeLimit(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $limit = round((float) $value, 2);
        if ($limit < 0) {
            throw new \InvalidArgumentException('A Store credit limit cannot be negative.');
        }

        return $limit;
    }

    public function accountLink(): BelongsTo
    {
        return $this->belongsTo(OnlineStoreAccountLink::class, 'account_link_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
