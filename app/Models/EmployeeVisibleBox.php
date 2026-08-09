<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmployeeVisibleBox extends Model
{
    use HasFactory;

    protected $table = 'employee_visible_boxes';

    protected $fillable = [
        'employee_id',
        'box_id',
    ];

    public function employee()
    {
        return $this->belongsTo(EmployeeDetail::class, 'employee_id');
    }

    public function box()
    {
        return $this->belongsTo(Box::class, 'box_id');
    }
}
