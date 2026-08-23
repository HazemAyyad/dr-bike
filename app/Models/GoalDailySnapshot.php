<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GoalDailySnapshot extends Model
{
    protected $table = 'goal_daily_snapshots';

    protected $fillable = [
        'goal_id',
        'snapshot_date',
        'current_value',
        'achievement_percentage',
    ];

    protected $casts = [
        'snapshot_date' => 'date:Y-m-d',
        'current_value' => 'float',
        'achievement_percentage' => 'float',
    ];
}
