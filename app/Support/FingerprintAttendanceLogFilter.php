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

    /** ZKTeco verify modes that represent real attendance scans. */
    private const VALID_VERIFY_TYPES = ['0', '1', '2', '3', '4', '5', '15'];

    private const BLOCKED_PINS = ['OPLOG', 'USER', 'FP', 'OPERLOG'];

    /** ZKTeco OPLOG admin/operation codes (digit after "OPLOG") are typically 0–100. */
    private const OPLOG_OPERATION_CODE_MAX = 100;

    public static function isValidDeviceUserPin(string $pin): bool
    {
        $pin = trim($pin);

        if ($pin === '' || self::containsOperlogMarker($pin)) {
            return false;
        }

        return preg_match('/^[1-9][0-9]{0,7}$/', $pin) === 1;
    }

    public static function containsOperlogMarker(string $value): bool
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return false;
        }

        $upper = strtoupper($trimmed);
        if (str_contains($upper, 'OPLOG') || str_contains($upper, 'OPERLOG')) {
            return true;
        }

        return preg_match('/^OPLOG[\s\-_]?\d{0,3}$/i', $trimmed) === 1;
    }

    /**
     * Numeric codes that appear in OPLOG lines (0–100), not employee PINs.
     */
    public static function isOperlogOperationCodePin(string $pin): bool
    {
        $pin = trim($pin);
        if (! preg_match('/^\d+$/', $pin)) {
            return false;
        }

        $n = (int) $pin;

        return $n >= 0 && $n <= self::OPLOG_OPERATION_CODE_MAX;
    }

    /**
     * Activity-log display: hide historical OPLOG junk already stored in DB.
     */
    public static function isDisplayableAttendanceLog(
        FingerprintRawLog $log,
        ?string $resolvedEmployeeName = null,
        ?array $registeredPinsForDevice = null
    ): bool {
        if (! self::isAttendanceLog($log)) {
            return false;
        }

        $pin = trim((string) $log->device_user_id);
        if (self::containsOperlogMarker($pin)) {
            return false;
        }

        $err = strtolower(trim((string) ($log->processing_error ?? '')));
        if ($err !== '' && (str_contains($err, 'oplog') || str_contains($err, 'operlog'))) {
            return false;
        }

        if ($log->processing_status === 'ignored' && $err === 'operlog_not_attendance') {
            return false;
        }

        $employeeName = trim((string) ($resolvedEmployeeName ?? ''));
        $isProcessed = trim((string) ($log->processing_status ?? '')) === 'processed';

        if ($isProcessed || $employeeName !== '') {
            return true;
        }

        if ($registeredPinsForDevice !== null && in_array($pin, $registeredPinsForDevice, true)) {
            return true;
        }

        // Unmapped 0–100 codes are almost always OPLOG operation codes in old data.
        if (self::isOperlogOperationCodePin($pin)) {
            return false;
        }

        return true;
    }

    public static function apply(Builder $query): Builder
    {
        return $query
            ->whereNotNull('device_user_id')
            ->where('device_user_id', '!=', '')
            ->whereRaw('CAST(device_user_id AS UNSIGNED) > 0')
            ->whereRaw("UPPER(TRIM(device_user_id)) NOT LIKE '%OPLOG%'")
            ->whereRaw("UPPER(TRIM(device_user_id)) NOT IN ('".implode("','", self::BLOCKED_PINS)."')")
            ->whereNotNull('verify_type')
            ->where('verify_type', '!=', '')
            ->whereIn('verify_type', self::VALID_VERIFY_TYPES)
            ->whereIn('status', self::ATTENDANCE_STATUSES)
            ->whereRaw("UPPER(COALESCE(CAST(raw_payload AS CHAR), '')) NOT LIKE '%OPLOG%'")
            ->whereRaw("UPPER(COALESCE(CAST(raw_payload AS CHAR), '')) NOT LIKE '%OPERLOG%'");
    }

    public static function isAttendanceLog(FingerprintRawLog $log): bool
    {
        $pin = trim((string) $log->device_user_id);
        if (! self::isValidDeviceUserPin($pin)) {
            return false;
        }

        $pinUpper = strtoupper($pin);
        foreach (self::BLOCKED_PINS as $blocked) {
            if (str_contains($pinUpper, $blocked)) {
                return false;
            }
        }

        $verify = trim((string) ($log->verify_type ?? ''));
        if ($verify === '' || ! in_array($verify, self::VALID_VERIFY_TYPES, true)) {
            return false;
        }

        $status = trim((string) ($log->status ?? ''));
        if (! in_array($status, self::ATTENDANCE_STATUSES, true)) {
            return false;
        }

        if (self::payloadContainsOperlog($log->raw_payload)) {
            return false;
        }

        $err = strtolower(trim((string) ($log->processing_error ?? '')));
        if ($err !== '' && (str_contains($err, 'oplog') || str_contains($err, 'operlog'))) {
            return false;
        }

        return true;
    }

    /**
     * @param  mixed  $payload
     */
    public static function payloadContainsOperlog($payload): bool
    {
        if ($payload === null) {
            return false;
        }

        if (is_array($payload)) {
            $encoded = strtoupper(json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '');

            return str_contains($encoded, 'OPLOG') || str_contains($encoded, 'OPERLOG');
        }

        $text = strtoupper((string) $payload);

        return str_contains($text, 'OPLOG') || str_contains($text, 'OPERLOG');
    }

    public static function isAttendanceRow(string $deviceUserId, ?string $verifyType, ?string $status, mixed $row = null): bool
    {
        if (! self::isValidDeviceUserPin($deviceUserId)) {
            return false;
        }

        $verify = trim((string) ($verifyType ?? ''));
        if ($verify === '' || ! in_array($verify, self::VALID_VERIFY_TYPES, true)) {
            return false;
        }

        $stat = trim((string) ($status ?? ''));

        if (! in_array($stat, self::ATTENDANCE_STATUSES, true)) {
            return false;
        }

        if ($row !== null && self::payloadContainsOperlog(['row' => $row])) {
            return false;
        }

        return true;
    }
}
