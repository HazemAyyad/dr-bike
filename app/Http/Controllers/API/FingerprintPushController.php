<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\AttendanceDevice;
use App\Models\FingerprintRawLog;
use App\Support\AdmsDebugLogger;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class FingerprintPushController extends Controller
{
    /**
     * ZKTeco / ADMS standard endpoint (device calls /iclock/cdata).
     */
    public function iclockCdata(Request $request)
    {
        AdmsDebugLogger::log($request, 'iclock/cdata');

        $sn = (string) ($request->query('SN') ?? $request->query('sn') ?? '');
        $options = $request->query('options');
        $table = strtoupper((string) ($request->query('table') ?? ''));

        if ($request->isMethod('GET') && $options !== null) {
            $this->touchDeviceLastSeen($sn);
            AdmsDebugLogger::logOutcome('iclock/cdata', [
                'branch' => 'handshake',
                'sn' => $sn,
                'options' => $options,
            ]);

            return $this->iclockHandshakeResponse($sn !== '' ? $sn : 'unknown');
        }

        if ($table === 'ATTLOG' || $table === 'OPERLOG' || $request->isMethod('POST')) {
            AdmsDebugLogger::logOutcome('iclock/cdata', [
                'branch' => 'attendance',
                'sn' => $sn,
                'table' => $table,
            ]);

            return $this->attendance($request);
        }

        AdmsDebugLogger::logOutcome('iclock/cdata', [
            'branch' => 'noop_ok',
            'sn' => $sn,
            'table' => $table,
            'method' => $request->method(),
        ]);

        return response('OK', 200)->header('Content-Type', 'text/plain');
    }

    public function iclockGetRequest(Request $request)
    {
        AdmsDebugLogger::log($request, 'iclock/getrequest');

        $sn = (string) ($request->query('SN') ?? $request->query('sn') ?? '');
        $this->touchDeviceLastSeen($sn);

        AdmsDebugLogger::logOutcome('iclock/getrequest', ['sn' => $sn]);

        return response('OK', 200)->header('Content-Type', 'text/plain');
    }

    public function iclockDevicecmd(Request $request)
    {
        AdmsDebugLogger::log($request, 'iclock/devicecmd');

        return response('OK', 200)->header('Content-Type', 'text/plain');
    }

    public function iclockTest(Request $request)
    {
        AdmsDebugLogger::log($request, 'iclock/test');

        $body = implode("\n", [
            'OK',
            'Time: '.now()->toDateTimeString(),
            'Environment: '.app()->environment(),
            'Request IP: '.$request->ip(),
        ]);

        return response($body, 200)->header('Content-Type', 'text/plain');
    }

    protected function touchDeviceLastSeen(string $sn): void
    {
        if ($sn === '') {
            return;
        }

        AttendanceDevice::query()
            ->where('serial_number', $sn)
            ->update(['last_seen_at' => now()]);
    }

    protected function iclockHandshakeResponse(string $sn): \Illuminate\Http\Response
    {
        $body = implode("\n", [
            "GET OPTION FROM: {$sn}",
            'ATTLOGStamp=0',
            'OPERLOGStamp=0',
            'ATTPHOTOStamp=0',
            'ErrorDelay=60',
            'Delay=30',
            'TransTimes=00:00;23:59',
            'TransInterval=1',
            'TransFlag=TransData AttLog OpLog AttPhoto EnrollUser ChgUser NewUser',
            'Realtime=1',
            'Encrypt=0',
            '',
        ]);

        return response($body, 200)->header('Content-Type', 'text/plain');
    }

    public function attendance(Request $request)
    {
        AdmsDebugLogger::log($request, 'attendance');

        try {
            if (! AppSetting::getBool(AppSetting::KEY_ATTENDANCE_FINGERPRINT_ENABLED, false)) {
                AdmsDebugLogger::logOutcome('attendance', ['stop' => 'disabled']);

                return response('disabled', 200)->header('Content-Type', 'text/plain');
            }

            $syncMode = (string) AppSetting::get(AppSetting::KEY_FINGERPRINT_SYNC_MODE, 'disabled');
            if ($syncMode !== 'push') {
                AdmsDebugLogger::logOutcome('attendance', ['stop' => 'mode_not_push', 'mode' => $syncMode]);

                return response('mode_not_push', 200)->header('Content-Type', 'text/plain');
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
                AdmsDebugLogger::logOutcome('attendance', ['stop' => 'missing_sn', 'rows_parsed' => count($rows)]);

                return response('missing_sn', 200)->header('Content-Type', 'text/plain');
            }

            $device = AttendanceDevice::query()
                ->where('serial_number', $sn)
                ->first();
            if (! $device) {
                Log::warning('fingerprint_push.unknown_device', [
                    'sn' => $sn,
                    'ip' => $request->ip(),
                ]);
                AdmsDebugLogger::logOutcome('attendance', ['stop' => 'unknown_device', 'sn' => $sn]);

                return response('unknown_device', 200)->header('Content-Type', 'text/plain');
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

                $inserted++;
            }

            AdmsDebugLogger::logOutcome('attendance', [
                'stop' => 'ok',
                'sn' => $sn,
                'device_id' => (int) $device->id,
                'rows_parsed' => count($rows),
                'inserted' => $inserted,
                'ignored' => $ignored,
            ]);

            // ADMS devices typically require a simple plain-text "OK"
            return response('OK', 200)->header('Content-Type', 'text/plain');
        } catch (\Throwable $e) {
            Log::error('fingerprint_push.failed', [
                'message' => $e->getMessage(),
            ]);
            AdmsDebugLogger::logOutcome('attendance', [
                'stop' => 'exception',
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
                continue;
            }

            // Some firmware: "PIN\tTime\tStatus\tVerify\t..."
            $cols = preg_split("/\t+/", $line) ?: [];
            if (count($cols) >= 2 && ! str_contains($line, '=')) {
                $rows[] = [
                    'PIN' => trim($cols[0]),
                    'Time' => trim($cols[1]),
                    'Status' => isset($cols[2]) ? trim($cols[2]) : null,
                    'Verify' => isset($cols[3]) ? trim($cols[3]) : null,
                ];
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

