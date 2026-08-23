<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GoalEmployeeShare extends Model
{
    protected $table = 'goal_employee_shares';

    protected $fillable = [
        'goal_id',
        'employee_id',
        'shared_by_user_id',
    ];

    public function goal()
    {
        return $this->belongsTo(Goal::class);
    }

    public function employee()
    {
        return $this->belongsTo(EmployeeDetail::class, 'employee_id');
    }
}
