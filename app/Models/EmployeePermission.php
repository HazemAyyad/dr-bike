<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmployeePermission extends Model
{
    use HasFactory;
    protected $table = 'employee_permissions';
    protected $fillable = ['employee_id','permission_id'];

    public function employee(){
        return $this->belongsTo(EmployeeDetail::class,'employee_id');
    }

        public function permission(){
        return $this->belongsTo(Permission::class);
    }
}
