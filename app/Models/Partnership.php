<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Partnership extends Model
{
    use HasFactory;

    protected $fillable = [
        'partner_id',
        'project_id',
        'share',
        'partnership_percentage',
        'department_id',
        'sub_department_id',
        'product_id',
        'status',
        'type',
        'customer_id',
        'seller_id',
    ];

    // A partner belongs to a project
    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function subDepartment()
    {
        return $this->belongsTo(SubDepartment::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    // public function partner()
    // {
    //     return $this->belongsTo(Partner::class);
    // }

    public function customer(){
        return $this->belongsTo(Customer::class);
    }
    public function seller(){
        return $this->belongsTo(Seller::class);
    }


}
