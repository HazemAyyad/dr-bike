<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountingCutover extends Model
{
    protected $fillable = [
        'cutover_date', 'status', 'snapshot', 'journal_entry_ids', 'notes',
        'applied_by', 'applied_at',
    ];

    protected $casts = [
        'cutover_date' => 'date',
        'snapshot' => 'array',
        'journal_entry_ids' => 'array',
        'applied_at' => 'datetime',
    ];

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }

    public static function active(): ?self
    {
        return static::query()->where('status', 'applied')->latest('cutover_date')->latest('id')->first();
    }
}
