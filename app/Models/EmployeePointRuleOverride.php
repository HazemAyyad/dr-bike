<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmployeePointRuleOverride extends Model
{
    use HasFactory;

    protected $fillable = [
        'rule_id',
        'employee_id',
        'points',
        'operation_type',
        'is_excluded',
        'effective_from',
        'notes',
    ];

    protected $casts = [
        'points' => 'integer',
        'is_excluded' => 'boolean',
        'effective_from' => 'date',
    ];

    public function rule()
    {
        return $this->belongsTo(EmployeePointRule::class, 'rule_id');
    }

    public function employee()
    {
        return $this->belongsTo(EmployeeDetail::class, 'employee_id');
    }
}
