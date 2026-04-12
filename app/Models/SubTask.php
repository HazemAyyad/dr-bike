<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SubTask extends Model
{
    use HasFactory;
    protected $table = 'sub_tasks';
    protected $fillable = [
        'special_task_id',
        'name','description',
        'status','force_employee_to_add_img_for_sub_task',
        'admin_img',
    ];

    protected $casts = ['admin_img'=>'array'];

    public function specialTask(){
        return $this->belongsTo(SpecialTask::class);
    }
}
