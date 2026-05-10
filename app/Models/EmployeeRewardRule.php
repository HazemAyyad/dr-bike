<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmployeeRewardRule extends Model
{
    use HasFactory;

    protected $table = 'employee_reward_rules';

    protected $fillable = [
        'min_points',
        'max_points',
        'reward_amount',
        'status_label',
        'status_color',
        'is_active',
    ];

    protected $casts = [
        'min_points' => 'integer',
        'max_points' => 'integer',
        'reward_amount' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
