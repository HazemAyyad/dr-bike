<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MaintenanceServiceMedia extends Model
{
    use HasFactory;

    protected $table = 'maintenance_service_media';

    protected $fillable = [
        'maintenance_service_id',
        'file_name',
        'file_type',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function service()
    {
        return $this->belongsTo(MaintenanceService::class, 'maintenance_service_id');
    }
}
