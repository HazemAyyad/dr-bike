<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GoalLog extends Model
{
    use HasFactory;

    protected $table = 'goal_logs';
    protected $fillable = ['goal_id','title','description'];

    public function goal(){
        return $this->belongsTo(Goal::class);
    }
}
