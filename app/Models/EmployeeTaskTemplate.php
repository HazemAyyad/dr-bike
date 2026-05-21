<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmployeeTaskTemplate extends Model
{
    protected $fillable = [
        'employee_id',
        'name',
        'description',
        'notes',
        'points',
        'priority',
        'is_forced_to_upload_img',
        'requires_admin_review',
        'not_shown_for_employee',
        'admin_img',
        'audio',
        'recurrence_type',
        'recurrence_config',
        'time_window_start',
        'time_window_end',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'admin_img' => 'array',
        'recurrence_config' => 'array',
        'is_forced_to_upload_img' => 'boolean',
        'requires_admin_review' => 'boolean',
        'not_shown_for_employee' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(EmployeeDetail::class, 'employee_id');
    }

    public function subtasks(): HasMany
    {
        return $this->hasMany(EmployeeTaskTemplateSubtask::class, 'template_id')->orderBy('sort_order');
    }

    public function occurrences(): HasMany
    {
        return $this->hasMany(EmployeeTaskOccurrence::class, 'template_id');
    }
}
