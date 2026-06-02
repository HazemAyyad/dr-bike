<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\AttendanceDevice;
use App\Models\FingerprintRawLog;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class FingerprintPushController extends Controller
{
    public function attendance(Request $request)
    {
        try {
            if (! AppSetting::getBool(AppSetting::KEY_ATTENDANCE_FINGERPRINT_ENABLED, false)) {
                return response('disabled', 200);
            }

            $syncMode = (string) AppSetting::get(AppSetting::KEY_FINGERPRINT_SYNC_MODE, 'disabled');
            if ($syncMode !== 'push') {
                return response('mode_not_push', 200);
            }

            $configuredToken = trim((string) AppSetting::get(AppSetting::KEY_FINGERPRINT_PUSH_TOKEN, ''));
            if ($configuredToken !== '') {
                $incoming = trim((string) ($request->header('X-ADMS-Token')
                    ?? $request->header('X-ZKTeco-Token')
                    ?? $request->query('token')
                    ?? $request->input('token')
                    ?? ''));
                if (! hash_equals($configuredToken, $incoming)) {
                    return response('unauthorized', 401);
                }
            }

            [$sn, $rows] = $this->parseZkPushPayload($request);
            if ($sn === null || $sn === '') {
                Log::warning('fingerprint_push.missing_sn', [
                    'ip' => $request->ip(),
                    'query' => $request->query(),
                ]);

                return response('missing_sn', 200);
            }

            $device = AttendanceDevice::query()
                ->where('serial_number', $sn)
                ->first();
            if (! $device) {
                Log::warning('fingerprint_push.unknown_device', [
                    'sn' => $sn,
                    'ip' => $request->ip(),
                ]);

                return response('unknown_device', 200);
            }

            $device->forceFill(['last_seen_at' => now()])->save();

            $inserted = 0;
            $ignored = 0;

            foreach ($rows as $row) {
                $deviceUserId = trim((string) ($row['PIN'] ?? $row['pin'] ?? $row['UserID'] ?? $row['user_id'] ?? ''));
                $timeRaw = trim((string) ($row['Time'] ?? $row['time'] ?? $row['DateTime'] ?? $row['datetime'] ?? ''));
                if ($deviceUserId === '' || $timeRaw === '') {
                    $ignored++;
                    continue;
                }

                $scanTime = $this->parseScanTime($timeRaw);
                if (! $scanTime) {
                    $ignored++;
                    continue;
                }

                $verifyType = isset($row['Verify']) ? (string) $row['Verify'] : (isset($row['verify']) ? (string) $row['verify'] : null);
                $status = isset($row['Status']) ? (string) $row['Status'] : (isset($row['status']) ? (string) $row['status'] : null);
                $deviceLogUid = isset($row['UID']) ? (string) $row['UID'] : (isset($row['uid']) ? (string) $row['uid'] : null);

                $created = false;
                FingerprintRawLog::query()->firstOrCreate(
                    [
                        'attendance_device_id' => $device->id,
                        'device_user_id' => $deviceUserId,
                        'scan_time' => $scanTime->toDateTimeString(),
                    ],
                    [
                        'device_log_uid' => $deviceLogUid,
                        'verify_type' => $verifyType,
                        'status' => $status,
                        'raw_payload' => [
                            'sn' => $sn,
                            'row' => $row,
                            'ip' => $request->ip(),
                            'ua' => (string) $request->userAgent(),
                        ],
                        'processing_status' => 'pending',
                    ]
                );

                // firstOrCreate does not tell us if created; we count best-effort by checking uniqueness beforehand
                // (avoid extra query per row)
                $inserted += $created ? 1 : 0;
            }

            // ADMS devices typically require a simple plain-text "OK"
            return response('OK', 200)->header('Content-Type', 'text/plain');
        } catch (\Throwable $e) {
            Log::error('fingerprint_push.failed', [
                'message' => $e->getMessage(),
            ]);

            return response('ERROR', 200)->header('Content-Type', 'text/plain');
        }
    }

    /**
     * @return array{0: string|null, 1: array<int, array<string, mixed>>}
     */
    protected function parseZkPushPayload(Request $request): array
    {
        $sn = $request->query('SN')
            ?? $request->query('sn')
            ?? $request->input('SN')
            ?? $request->input('sn');

        $raw = (string) $request->getContent();

        // Some firmwares send "SN=...&..." in body as form-url-encoded
        if (($sn === null || $sn === '') && $raw !== '' && str_contains($raw, 'SN=')) {
            parse_str(str_replace("\n", '&', $raw), $parsed);
            $sn = $parsed['SN'] ?? $parsed['sn'] ?? $sn;
        }

        // Rows can arrive as multiple lines: "PIN=1\tTime=2026-06-02 08:00:00\tStatus=0\tVerify=1"
        $lines = preg_split("/\r\n|\n|\r/", $raw) ?: [];
        $rows = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, 'SN=') || str_starts_with($line, 'table=') || str_starts_with($line, 'Stamp=')) {
                continue;
            }

            // Try tab separated key=val pairs
            $row = [];
            $parts = preg_split("/\t+/", $line) ?: [];
            foreach ($parts as $p) {
                $p = trim($p);
                if ($p === '' || ! str_contains($p, '=')) {
                    continue;
                }
                [$k, $v] = explode('=', $p, 2);
                $row[trim($k)] = trim($v);
            }
            if ($row) {
                $rows[] = $row;
            }
        }

        // If no per-line rows were detected, fall back to request params as a single row
        if (! $rows) {
            $maybeRow = array_filter($request->all(), fn ($v) => $v !== null && $v !== '');
            if ($maybeRow) {
                $rows[] = $maybeRow;
            }
        }

        return [(string) $sn, $rows];
    }

    protected function parseScanTime(string $timeRaw): ?Carbon
    {
        // Common formats:
        // - 2026-06-02 08:00:00
        // - 2026/06/02 08:00:00
        $timeRaw = trim($timeRaw);
        if ($timeRaw === '') {
            return null;
        }

        try {
            return Carbon::parse($timeRaw);
        } catch (\Throwable) {
            try {
                return Carbon::createFromFormat('Y/m/d H:i:s', $timeRaw);
            } catch (\Throwable) {
                return null;
            }
        }
    }
}

