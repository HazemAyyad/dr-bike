<?php

namespace App\Services;

use App\Models\AttendanceDevice;

class AttendanceDeviceService
{
    /**
     * Best-effort TCP connectivity test.
     *
     * @return array{ok: bool, message: string, latency_ms: int|null}
     */
    public function testConnection(AttendanceDevice $device): array
    {
        $ip = trim((string) $device->ip_address);
        $port = (int) ($device->port ?? 4370);
        if ($ip === '' || $port <= 0) {
            return ['ok' => false, 'message' => 'Device IP/Port not configured.', 'latency_ms' => null];
        }

        $start = microtime(true);
        $errno = 0;
        $errstr = '';
        $fp = @fsockopen($ip, $port, $errno, $errstr, 3.0);
        $latency = (int) round((microtime(true) - $start) * 1000);

        if ($fp === false) {
            return [
                'ok' => false,
                'message' => $errstr !== '' ? $errstr : ("Connection failed (errno {$errno})."),
                'latency_ms' => $latency,
            ];
        }

        fclose($fp);

        return ['ok' => true, 'message' => 'Connection successful.', 'latency_ms' => $latency];
    }
}

