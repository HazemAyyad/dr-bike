<?php

namespace App\Support;

use App\Models\EmployeeAttendanceScan;
use App\Models\FingerprintRawLog;
use Carbon\Carbon;

class AttendanceScanPresenter
{
    public static function serverReceivedAt(EmployeeAttendanceScan $scan): Carbon
    {
        if ($scan->server_received_at) {
            return Carbon::parse($scan->server_received_at);
        }

        if ($scan->fingerprint_raw_log_id) {
            $raw = $scan->relationLoaded('fingerprintRawLog')
                ? $scan->fingerprintRawLog
                : FingerprintRawLog::query()->find($scan->fingerprint_raw_log_id);
            if ($raw) {
                return $raw->serverReceivedAt();
            }
        }

        if (($scan->source ?? '') === 'fingerprint') {
            return Carbon::parse($scan->created_at ?? now());
        }

        return Carbon::parse($scan->scanned_at);
    }

    public static function isFingerprint(EmployeeAttendanceScan $scan): bool
    {
        return ($scan->source ?? '') === 'fingerprint'
            || $scan->fingerprint_raw_log_id !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function scanToApi(EmployeeAttendanceScan $scan): array
    {
        $payload = [
            'at' => Carbon::parse($scan->scanned_at)->toIso8601String(),
            'direction' => (string) $scan->direction,
            'source' => $scan->source,
            'is_reverse_checkout' => (bool) ($scan->is_reverse_checkout ?? false),
        ];

        if (self::isFingerprint($scan)) {
            $payload['device_at'] = Carbon::parse($scan->scanned_at)->toIso8601String();
            $payload['server_at'] = self::serverReceivedAt($scan)->toIso8601String();
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public static function segmentToApi(
        EmployeeAttendanceScan $checkIn,
        ?EmployeeAttendanceScan $checkOut,
        ?int $workedMinutes,
        bool $open = false
    ): array {
        $segment = [
            'check_in_at' => Carbon::parse($checkIn->scanned_at)->toIso8601String(),
            'check_out_at' => $checkOut ? Carbon::parse($checkOut->scanned_at)->toIso8601String() : null,
            'worked_minutes' => $workedMinutes,
            'open' => $open,
        ];

        if (self::isFingerprint($checkIn)) {
            $segment['check_in_device_at'] = $segment['check_in_at'];
            $segment['check_in_server_at'] = self::serverReceivedAt($checkIn)->toIso8601String();
        }
        if ($checkOut && self::isFingerprint($checkOut)) {
            $segment['check_out_device_at'] = $segment['check_out_at'];
            $segment['check_out_server_at'] = self::serverReceivedAt($checkOut)->toIso8601String();
        }

        return $segment;
    }

    /**
     * @return array<string, mixed>
     */
    public static function checkInSummary(?EmployeeAttendanceScan $scan): array
    {
        if (! $scan) {
            return [];
        }

        $out = [
            'at' => Carbon::parse($scan->scanned_at)->toIso8601String(),
            'source' => $scan->source,
        ];

        if (self::isFingerprint($scan)) {
            $out['device_at'] = $out['at'];
            $out['server_at'] = self::serverReceivedAt($scan)->toIso8601String();
        }

        return $out;
    }
}
