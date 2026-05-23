<?php

namespace App\Models;

use App\Models\EmployeeDetail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeTaskOccurrenceSubtask extends Model
{
    protected $fillable = [
        'occurrence_id',
        'template_subtask_id',
        'name',
        'description',
        'sort_order',
        'requires_image',
        'bonus_points',
        'status',
        'completed_by_employee_id',
        'admin_img',
        'employee_img',
    ];

    protected $casts = [
        'admin_img' => 'array',
        'employee_img' => 'array',
        'requires_image' => 'boolean',
    ];

    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(EmployeeTaskOccurrence::class, 'occurrence_id');
    }

    public function completedByEmployee(): BelongsTo
    {
        return $this->belongsTo(EmployeeDetail::class, 'completed_by_employee_id');
    }
}
