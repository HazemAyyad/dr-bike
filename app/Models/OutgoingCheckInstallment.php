<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OutgoingCheckInstallment extends Model
{
    protected $fillable = ['outgoing_check_id', 'amount', 'due_date', 'instrument_type', 'check_id', 'bank_name', 'status', 'notes'];

    protected $casts = ['amount' => 'float', 'due_date' => 'date'];

    public function check()
    {
        return $this->belongsTo(OutgoingCheck::class, 'outgoing_check_id');
    }
}
