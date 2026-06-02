<?php

namespace App\Services;

use App\Models\AttendanceDevice;

/**
 * ZKTeco-compatible pull integration (port 4370).
 *
 * This is intentionally driver-pluggable: if no stable library is available
 * in this project yet, methods return structured "not implemented" errors.
 * Replace internals with a real ZK driver later without changing controllers.
 */
class ZktecoPullService
{
    /**
     * @return array{ok: bool, message: string, data?: mixed}
     */
    public function testConnection(AttendanceDevice $device): array
    {
        // We keep a TCP test separately in AttendanceDeviceService.
        // Here we reserve a place for protocol-level validation (ZK handshake).
        return [
            'ok' => false,
            'message' => 'ZKTeco driver not installed yet. TCP test is available.',
        ];
    }

    /**
     * @return array{ok: bool, message: string, users: array<int, array<string, mixed>>}
     */
    public function fetchUsers(AttendanceDevice $device): array
    {
        return [
            'ok' => false,
            'message' => 'ZKTeco driver not installed yet.',
            'users' => [],
        ];
    }

    /**
     * @return array{ok: bool, message: string, logs: array<int, array<string, mixed>>}
     */
    public function fetchAttendanceLogs(AttendanceDevice $device): array
    {
        return [
            'ok' => false,
            'message' => 'ZKTeco driver not installed yet.',
            'logs' => [],
        ];
    }
}

