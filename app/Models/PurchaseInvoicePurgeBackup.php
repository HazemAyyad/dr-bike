<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseInvoicePurgeBackup extends Model
{
    protected $fillable = [
        'reference',
        'bill_id',
        'bill_reference',
        'workflow_status',
        'reason',
        'payload',
        'result_summary',
        'created_by',
    ];

    protected $hidden = ['payload'];

    protected $casts = [
        'payload' => 'array',
        'result_summary' => 'array',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
