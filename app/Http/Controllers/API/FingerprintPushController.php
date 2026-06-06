<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\AttendanceDevice;
use App\Models\FingerprintRawLog;
use App\Services\FingerprintAttendanceProcessor;
use App\Support\AdmsDebugLogger;
use App\Support\FingerprintAttendanceLogFilter;
use App\Models\FingerprintDeviceUser;
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

        // OPERLOG / OPLOG — save to raw log; attendance processing stays separate.
        if (in_array($table, ['OPERLOG', 'OPLOG'], true)) {
            $this->touchDeviceLastSeen($sn);
            AdmsDebugLogger::logOutcome('iclock/cdata', [
                'branch' => 'operlog_persist',
                'sn' => $sn,
                'table' => $table,
            ]);

            return $this->attendance($request, $table);
        }

        if ($table === 'ATTLOG' || $request->isMethod('POST')) {
            AdmsDebugLogger::logOutcome('iclock/cdata', [
                'branch' => 'attendance',
                'sn' => $sn,
                'table' => $table,
            ]);

            return $this->attendance($request, $table !== '' ? $table : 'ATTLOG');
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

    public function attendance(Request $request, ?string $pushTable = null)
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

            [$sn, $rows] = $this->parseZkPushPayload($request, $pushTable);
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

            $rawBody = (string) $request->getContent();
            $saved = 0;
            foreach ($rows as $row) {
                if ($this->saveRawLogRow($device, $sn, $row, $request, $pushTable)) {
                    $saved++;
                }
            }

            if ($saved === 0 && trim($rawBody) !== '') {
                $fallback = $this->fallbackRowFromLine(trim($rawBody), $pushTable);
                if ($this->saveRawLogRow($device, $sn, $fallback, $request, $pushTable)) {
                    $saved = 1;
                }
            }

            if ($saved === 0) {
                Log::warning('fingerprint_push.no_rows_saved', [
                    'sn' => $sn,
                    'device_id' => (int) $device->id,
                    'table' => $pushTable,
                    'rows_parsed' => count($rows),
                    'raw_length' => strlen($rawBody),
                    'raw_preview' => substr($rawBody, 0, 500),
                ]);
            }

            AdmsDebugLogger::logOutcome('attendance', [
                'stop' => 'ok',
                'sn' => $sn,
                'device_id' => (int) $device->id,
                'table' => $pushTable,
                'rows_parsed' => count($rows),
                'saved' => $saved,
            ]);

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
     * Persist one parsed push row — no ingest filtering (filter in app UI only).
     *
     * @param  array<string, mixed>  $row
     */
    protected function saveRawLogRow(
        AttendanceDevice $device,
        string $sn,
        array $row,
        Request $request,
        ?string $pushTable = null
    ): bool {
        $deviceUserId = trim((string) ($row['PIN'] ?? $row['pin'] ?? $row['UserID'] ?? $row['user_id'] ?? ''));
        $timeRaw = trim((string) ($row['Time'] ?? $row['time'] ?? $row['DateTime'] ?? $row['datetime'] ?? ''));

        if ($deviceUserId === '' && isset($row['_raw_line'])) {
            $deviceUserId = $this->guessPinFromLine((string) $row['_raw_line']);
        }
        if ($deviceUserId === '') {
            $deviceUserId = 'RAW';
        }

        if ($timeRaw === '' && isset($row['_raw_line'])) {
            $timeRaw = $this->guessTimeFromLine((string) $row['_raw_line']) ?? '';
        }
        $scanTime = $timeRaw !== '' ? $this->parseScanTime($timeRaw) : null;
        $scanTime ??= now();

        $verifyType = isset($row['Verify']) ? (string) $row['Verify'] : (isset($row['verify']) ? (string) $row['verify'] : null);
        $status = isset($row['Status']) ? (string) $row['Status'] : (isset($row['status']) ? (string) $row['status'] : null);
        $deviceLogUid = isset($row['UID']) ? (string) $row['UID'] : (isset($row['uid']) ? (string) $row['uid'] : null);

        if (FingerprintAttendanceLogFilter::isValidDeviceUserPin($deviceUserId)) {
            FingerprintDeviceUser::query()->firstOrCreate(
                [
                    'attendance_device_id' => $device->id,
                    'device_user_id' => $deviceUserId,
                ],
                ['last_synced_at' => now()]
            );
        }

        $isAttendance = FingerprintAttendanceLogFilter::isAttendanceRow($deviceUserId, $verifyType, $status, $row);

        $rawLog = FingerprintRawLog::query()->create([
            'attendance_device_id' => $device->id,
            'device_user_id' => $deviceUserId,
            'scan_time' => $scanTime->toDateTimeString(),
            'device_log_uid' => $deviceLogUid,
            'verify_type' => $verifyType,
            'status' => $status,
            'raw_payload' => [
                'sn' => $sn,
                'table' => $pushTable,
                'row' => $row,
                'ip' => $request->ip(),
                'ua' => (string) $request->userAgent(),
                'received_at' => now()->toIso8601String(),
            ],
            'processing_status' => $isAttendance ? 'pending' : 'ignored',
            'processing_error' => $isAttendance ? null : 'not_processed_for_attendance',
        ]);

        if ($isAttendance) {
            app(FingerprintAttendanceProcessor::class)->processRawLog($rawLog);
        }

        return true;
    }

    protected function guessPinFromLine(string $line): string
    {
        $line = trim($line);
        if ($line === '') {
            return 'RAW';
        }

        if (preg_match('/^OPLOG[\s\t]+(\d+)/i', $line, $m)) {
            return 'OPLOG-'.$m[1];
        }
        if (preg_match('/^OPLOG\b/i', $line)) {
            return 'OPLOG';
        }
        if (preg_match('/^USER\b/i', $line)) {
            return 'USER';
        }
        if (preg_match('/^FP\b/i', $line)) {
            return 'FP';
        }
        if (preg_match('/^(\d+)/', $line, $m)) {
            return $m[1];
        }
        if (preg_match('/^(\S+)/', $line, $m)) {
            return $m[1];
        }

        return 'RAW';
    }

    protected function guessTimeFromLine(string $line): ?string
    {
        if (preg_match('/(\d{4}[-\/]\d{2}[-\/]\d{2}\s+\d{2}:\d{2}:\d{2})/', $line, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * @return array{0: string|null, 1: array<int, array<string, mixed>>}
     */
    protected function parseZkPushPayload(Request $request, ?string $pushTable = null): array
    {
        $sn = $request->query('SN')
            ?? $request->query('sn')
            ?? $request->input('SN')
            ?? $request->input('sn');

        $raw = (string) $request->getContent();

        if (($sn === null || $sn === '') && $raw !== '' && str_contains($raw, 'SN=')) {
            parse_str(str_replace("\n", '&', $raw), $parsed);
            $sn = $parsed['SN'] ?? $parsed['sn'] ?? $sn;
        }

        $lines = preg_split("/\r\n|\n|\r/", $raw) ?: [];
        $rows = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, 'SN=') || str_starts_with($line, 'table=') || str_starts_with($line, 'Stamp=')) {
                continue;
            }

            $parsed = $this->parsePushLine($line, $pushTable);
            if ($parsed !== null) {
                $rows[] = $parsed;
            }
        }

        if (! $rows) {
            $maybeRow = array_filter($request->all(), fn ($v) => $v !== null && $v !== '');
            if ($maybeRow) {
                $maybeRow['_table'] = $pushTable;
                $rows[] = $maybeRow;
            } elseif ($raw !== '') {
                $rows[] = $this->fallbackRowFromLine($raw, $pushTable);
            }
        }

        return [(string) $sn, $rows];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function parsePushLine(string $line, ?string $pushTable = null): ?array
    {
        if (preg_match('/^(\d+)\s+(\d{4}[-\/]\d{2}[-\/]\d{2}\s+\d{2}:\d{2}:\d{2})\s+(\d+)\s+(\d+)/', $line, $m)) {
            return [
                'PIN' => $m[1],
                'Time' => $m[2],
                'Status' => $m[3],
                'Verify' => $m[4],
                '_raw_line' => $line,
                '_table' => $pushTable,
            ];
        }

        if (preg_match('/^(\d+)\s+(\d{4}[-\/]\d{2}[-\/]\d{2}\s+\d{2}:\d{2}:\d{2})/', $line, $m)) {
            return [
                'PIN' => $m[1],
                'Time' => $m[2],
                '_raw_line' => $line,
                '_table' => $pushTable,
            ];
        }

        if (preg_match('/^OPLOG\b/i', $line)) {
            $row = $this->fallbackRowFromLine($line, $pushTable);
            $row['_kind'] = 'oplog';

            return $row;
        }

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
            $row['_raw_line'] = $line;
            $row['_table'] = $pushTable;

            return $row;
        }

        $cols = preg_split("/\t+/", $line) ?: [];
        if (count($cols) >= 2 && ! str_contains($line, '=')) {
            return [
                'PIN' => trim($cols[0]),
                'Time' => trim($cols[1]),
                'Status' => isset($cols[2]) ? trim($cols[2]) : null,
                'Verify' => isset($cols[3]) ? trim($cols[3]) : null,
                '_raw_line' => $line,
                '_table' => $pushTable,
            ];
        }

        return $this->fallbackRowFromLine($line, $pushTable);
    }

    /**
     * @return array<string, mixed>
     */
    protected function fallbackRowFromLine(string $line, ?string $pushTable = null): array
    {
        $line = trim($line);

        return [
            'PIN' => $this->guessPinFromLine($line),
            'Time' => $this->guessTimeFromLine($line) ?? now()->toDateTimeString(),
            '_raw_line' => $line,
            '_table' => $pushTable,
        ];
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

