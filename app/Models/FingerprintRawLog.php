<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FingerprintRawLog extends Model
{
    use HasFactory;

    protected $table = 'fingerprint_raw_logs';

    protected $fillable = [
        'attendance_device_id',
        'device_user_id',
        'device_log_uid',
        'scan_time',
        'verify_type',
        'status',
        'raw_payload',
        'processed_at',
        'processing_status',
        'processing_error',
    ];

    protected $casts = [
        'scan_time' => 'datetime',
        'raw_payload' => 'array',
        'processed_at' => 'datetime',
    ];

    public function serverReceivedAt(): \Carbon\Carbon
    {
        $payload = $this->raw_payload;
        if (is_array($payload) && ! empty($payload['received_at'])) {
            return \Carbon\Carbon::parse($payload['received_at']);
        }

        return $this->created_at ?? now();
    }

    public function attendanceDevice()
    {
        return $this->belongsTo(AttendanceDevice::class, 'attendance_device_id');
    }
}

