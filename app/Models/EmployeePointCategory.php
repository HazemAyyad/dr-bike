<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmployeePointCategory extends Model
{
    use HasFactory;

    protected $table = 'employee_point_categories';

    public const OPERATION_ADD = 'add';
    public const OPERATION_DEDUCT = 'deduct';

    protected $fillable = [
        'name_ar',
        'name_en',
        'code',
        'operation_type',
        'default_points',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'default_points' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    public function logs()
    {
        return $this->hasMany(EmployeePointsLog::class, 'category_id');
    }
}
