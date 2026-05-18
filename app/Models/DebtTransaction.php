<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DebtTransaction extends Model
{
    protected $fillable = [
        'customer_id',
        'seller_id',
        'type',
        'amount',
        'balance_after',
        'note',
        'receipt_images',
        'transaction_date',
        'box_id',
        'source',
        'source_id',
        'archived_at',
        'created_by',
    ];

    protected $casts = [
        'receipt_images' => 'array',
        'transaction_date' => 'date',
        'archived_at' => 'datetime',
        'amount' => 'decimal:2',
        'balance_after' => 'decimal:2',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    public function box(): BelongsTo
    {
        return $this->belongsTo(Box::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive($query)
    {
        return $query->whereNull('archived_at');
    }

    public function scopeForCustomer($query, int $customerId)
    {
        return $query->where('customer_id', $customerId)->whereNull('seller_id');
    }

    public function scopeForSeller($query, int $sellerId)
    {
        return $query->where('seller_id', $sellerId)->whereNull('customer_id');
    }
}
