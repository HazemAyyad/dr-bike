<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmployeePointRule extends Model
{
    use HasFactory;

    public const PERIOD_DAILY = 'daily';
    public const PERIOD_WEEKLY = 'weekly';
    public const PERIOD_MONTHLY = 'monthly';

    public const CONDITION_EMPLOYEE_COMPLETED_ALL_TASKS_BEFORE_TIME = 'employee_completed_all_tasks_before_time';
    public const CONDITION_ALL_EMPLOYEES_COMPLETED_TASKS = 'all_employees_completed_tasks';
    public const CONDITION_EMPLOYEE_HAS_INCOMPLETE_TASKS = 'employee_has_incomplete_tasks';

    protected $fillable = [
        'name',
        'description',
        'condition_type',
        'period_type',
        'operation_type',
        'default_points',
        'applies_to_all',
        'settings',
        'effective_from',
        'is_active',
        'sort_order',
        'created_by',
    ];

    protected $casts = [
        'default_points' => 'integer',
        'applies_to_all' => 'boolean',
        'settings' => 'array',
        'effective_from' => 'date',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function overrides()
    {
        return $this->hasMany(EmployeePointRuleOverride::class, 'rule_id');
    }

    public function employees()
    {
        return $this->belongsToMany(
            EmployeeDetail::class,
            'employee_point_rule_employees',
            'rule_id',
            'employee_id'
        )->withTimestamps();
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
