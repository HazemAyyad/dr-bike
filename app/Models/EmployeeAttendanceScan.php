<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

class EmployeeAttendanceScan extends Model
{
    protected $table = 'employee_attendance_scans';

    protected $fillable = [
        'employee_id',
        'work_date',
        'scanned_at',
        'direction',
        'source',
        'server_received_at',
        'fingerprint_raw_log_id',
    ];

    protected $casts = [
        'work_date' => 'date',
        'scanned_at' => 'datetime',
        'server_received_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(EmployeeDetail::class, 'employee_id');
    }

    public function fingerprintRawLog(): BelongsTo
    {
        return $this->belongsTo(FingerprintRawLog::class, 'fingerprint_raw_log_id');
    }

    public static function computeWorkedMinutes(Collection $scans): int
    {
        $total = 0;
        $pendingIn = null;
        foreach ($scans as $s) {
            if ($s->direction === 'in') {
                $pendingIn = $s->scanned_at;
            } elseif ($s->direction === 'out' && $pendingIn !== null) {
                $total += Carbon::parse($pendingIn)->diffInMinutes(Carbon::parse($s->scanned_at));
                $pendingIn = null;
            }
        }

        return $total;
    }

    public static function computeAwayMinutes(Collection $scans): int
    {
        $total = 0;
        $pendingOut = null;
        foreach ($scans as $s) {
            if ($s->direction === 'out') {
                $pendingOut = $s->scanned_at;
            } elseif ($s->direction === 'in' && $pendingOut !== null) {
                $total += Carbon::parse($pendingOut)->diffInMinutes(Carbon::parse($s->scanned_at));
                $pendingOut = null;
            }
        }

        return $total;
    }
}
