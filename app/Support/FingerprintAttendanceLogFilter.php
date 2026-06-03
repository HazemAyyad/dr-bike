<?php

namespace App\Support;

use App\Models\FingerprintRawLog;
use Illuminate\Database\Eloquent\Builder;

/**
 * Filters ZKTeco ATTLOG rows from device operation logs (OPLOG/OPERLOG).
 */
final class FingerprintAttendanceLogFilter
{
    /** ATTLOG check-in / check-out status codes only. */
    private const ATTENDANCE_STATUSES = ['0', '1'];

    public static function apply(Builder $query): Builder
    {
        return $query
            ->whereNotNull('device_user_id')
            ->where('device_user_id', '!=', '')
            ->where('device_user_id', '!=', '0')
            ->whereRaw("UPPER(TRIM(device_user_id)) NOT IN ('OPLOG', 'USER', 'FP')")
            ->whereNotNull('verify_type')
            ->where('verify_type', '!=', '')
            ->whereIn('status', self::ATTENDANCE_STATUSES)
            ->where(function (Builder $q) {
                $q->whereNull('raw_payload')
                    ->orWhere(function (Builder $inner) {
                        $inner->whereRaw("CAST(raw_payload AS CHAR) NOT LIKE '%OPLOG %'")
                            ->whereRaw("CAST(raw_payload AS CHAR) NOT LIKE '%\"OPLOG\"%'")
                            ->whereRaw("UPPER(CAST(raw_payload AS CHAR)) NOT LIKE '%OPERLOG%'");
                    });
            });
    }

    public static function isAttendanceLog(FingerprintRawLog $log): bool
    {
        $pin = strtoupper(trim((string) $log->device_user_id));
        if ($pin === '' || $pin === '0' || in_array($pin, ['OPLOG', 'USER', 'FP'], true)) {
            return false;
        }

        $verify = trim((string) ($log->verify_type ?? ''));
        if ($verify === '') {
            return false;
        }

        $status = trim((string) ($log->status ?? ''));
        if (! in_array($status, self::ATTENDANCE_STATUSES, true)) {
            return false;
        }

        $payload = $log->raw_payload;
        if (is_array($payload)) {
            $encoded = strtoupper(json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '');
            if (str_contains($encoded, 'OPLOG') || str_contains($encoded, 'OPERLOG')) {
                return false;
            }
        }

        return true;
    }
}
