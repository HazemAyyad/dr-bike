<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OutgoingCheckSettlement extends Model
{
    protected $fillable = ['outgoing_check_id', 'box_id', 'amount', 'paid_at', 'idempotency_key', 'notes', 'created_by'];

    protected $casts = ['amount' => 'float', 'paid_at' => 'date'];

    public function check()
    {
        return $this->belongsTo(OutgoingCheck::class, 'outgoing_check_id');
    }

    public function box()
    {
        return $this->belongsTo(Box::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
