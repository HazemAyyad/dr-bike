<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProjectExpense extends Model
{
    use HasFactory;

    protected $table = 'project_expenses';

    protected $fillable = ['project_id', 'expenses', 'box_id', 'currency', 'expense_date', 'notes', 'created_by'];

    protected $casts = ['expense_date' => 'date'];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function box()
    {
        return $this->belongsTo(Box::class);
    }
}
