<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmployeeSignature extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'employee_id', 'name', 'source', 'original_path', 'processed_path',
        'signature_hash', 'is_default', 'approved_at',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'approved_at' => 'datetime',
    ];

    protected $hidden = ['signature_hash'];

    public function employee()
    {
        return $this->belongsTo(EmployeeDetail::class);
    }
}
