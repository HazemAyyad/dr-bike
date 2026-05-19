<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeTaskTemplateSubtask extends Model
{
    protected $fillable = [
        'template_id',
        'name',
        'description',
        'sort_order',
        'requires_image',
        'bonus_points',
        'admin_img',
    ];

    protected $casts = [
        'admin_img' => 'array',
        'requires_image' => 'boolean',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(EmployeeTaskTemplate::class, 'template_id');
    }
}
