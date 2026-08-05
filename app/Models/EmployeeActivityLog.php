<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeActivityLog extends Model
{
    protected $fillable = [
        'employee_id',
        'actor_user_id',
        'module',
        'action',
        'title',
        'description',
        'subject_type',
        'subject_id',
        'amount',
        'metadata',
    ];

    protected $casts = [
        'amount' => 'float',
        'metadata' => 'array',
    ];

    public function employee()
    {
        return $this->belongsTo(EmployeeDetail::class, 'employee_id');
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
