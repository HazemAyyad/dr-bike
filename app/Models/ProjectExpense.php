<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProjectExpense extends Model
{
    use HasFactory;
    protected $table = 'project_expenses';
    protected $fillable = ['project_id','expenses','notes'];

    public function project(){
        return $this->belongsTo(Project::class);
    }
}
