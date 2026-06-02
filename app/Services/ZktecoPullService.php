<?php

namespace App\Services;

use App\Models\AttendanceDevice;
use Rats\Zkteco\Lib\ZKTeco;

/**
 * ZKTeco-compatible pull integration (port 4370).
 */
class ZktecoPullService
{
    /**
     * @return array{ok: bool, message: string, data?: mixed}
     */
    public function testConnection(AttendanceDevice $device): array
    {
        $ip = trim((string) $device->ip_address);
        $port = (int) ($device->port ?? 4370);

        if ($ip === '' || $port <= 0) {
            return ['ok' => false, 'message' => 'Device IP/Port not configured.'];
        }
        if (! function_exists('socket_create')) {
            return ['ok' => false, 'message' => 'PHP sockets extension is not enabled (ext-sockets). Enable it in php.ini then retry.'];
        }

        try {
            $zk = new ZKTeco($ip, $port);
            if (! $zk->connect()) {
                return ['ok' => false, 'message' => 'Device did not accept connection (ZK protocol).'];
            }

            try {
                $zk->disableDevice();
                $time = $zk->getTime();
            } finally {
                try {
                    $zk->enableDevice();
                } catch (\Throwable $e) {
                }
                try {
                    $zk->disconnect();
                } catch (\Throwable $e) {
                }
            }

            return [
                'ok' => true,
                'message' => 'ZK protocol connection successful.',
                'data' => ['device_time' => $time],
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * @return array{ok: bool, message: string, users: array<int, array<string, mixed>>}
     */
    public function fetchUsers(AttendanceDevice $device): array
    {
        $ip = trim((string) $device->ip_address);
        $port = (int) ($device->port ?? 4370);

        if ($ip === '' || $port <= 0) {
            return ['ok' => false, 'message' => 'Device IP/Port not configured.', 'users' => []];
        }
        if (! function_exists('socket_create')) {
            return ['ok' => false, 'message' => 'PHP sockets extension is not enabled (ext-sockets). Enable it in php.ini then retry.', 'users' => []];
        }

        try {
            $zk = new ZKTeco($ip, $port);
            if (! $zk->connect()) {
                return ['ok' => false, 'message' => 'Failed to connect to device.', 'users' => []];
            }

            try {
                $zk->disableDevice();
                $raw = $zk->getUser();
            } finally {
                try {
                    $zk->enableDevice();
                } catch (\Throwable $e) {
                }
                try {
                    $zk->disconnect();
                } catch (\Throwable $e) {
                }
            }

            $users = [];
            foreach (is_array($raw) ? $raw : [] as $u) {
                if (! is_array($u)) {
                    continue;
                }

                $deviceUserId = (string) ($u['userid'] ?? $u['id'] ?? '');
                $uid = $u['uid'] ?? null;
                if ($deviceUserId === '' && $uid !== null) {
                    $deviceUserId = (string) $uid;
                }
                if ($deviceUserId === '') {
                    continue;
                }

                $users[] = [
                    'device_user_id' => $deviceUserId,
                    'uid' => $uid,
                    'id' => $deviceUserId,
                    'name' => $u['name'] ?? null,
                    'privilege' => $u['role'] ?? null,
                    'card' => $u['cardno'] ?? null,
                    'password' => $u['password'] ?? null,
                    'enabled' => true,
                    'raw' => $u,
                ];
            }

            return ['ok' => true, 'message' => 'Fetched users.', 'users' => $users];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'users' => []];
        }
    }

    /**
     * @return array{ok: bool, message: string, logs: array<int, array<string, mixed>>}
     */
    public function fetchAttendanceLogs(AttendanceDevice $device): array
    {
        $ip = trim((string) $device->ip_address);
        $port = (int) ($device->port ?? 4370);

        if ($ip === '' || $port <= 0) {
            return ['ok' => false, 'message' => 'Device IP/Port not configured.', 'logs' => []];
        }
        if (! function_exists('socket_create')) {
            return ['ok' => false, 'message' => 'PHP sockets extension is not enabled (ext-sockets). Enable it in php.ini then retry.', 'logs' => []];
        }

        try {
            $zk = new ZKTeco($ip, $port);
            if (! $zk->connect()) {
                return ['ok' => false, 'message' => 'Failed to connect to device.', 'logs' => []];
            }

            try {
                $zk->disableDevice();
                $raw = $zk->getAttendance();
            } finally {
                try {
                    $zk->enableDevice();
                } catch (\Throwable $e) {
                }
                try {
                    $zk->disconnect();
                } catch (\Throwable $e) {
                }
            }

            $logs = [];
            foreach (is_array($raw) ? $raw : [] as $l) {
                if (! is_array($l)) {
                    continue;
                }

                $deviceUserId = (string) ($l['id'] ?? '');
                if ($deviceUserId === '') {
                    $deviceUserId = (string) ($l['uid'] ?? '');
                }
                $ts = $l['timestamp'] ?? null;
                if ($deviceUserId === '' || $ts === null) {
                    continue;
                }

                $logs[] = [
                    'device_user_id' => $deviceUserId,
                    'uid' => $l['uid'] ?? null,
                    'verify_type' => $l['state'] ?? null,
                    'scan_time' => $ts,
                    'timestamp' => $ts,
                    'status' => $l['type'] ?? null,
                    'device_log_uid' => $l['uid'] ?? null,
                    'raw' => $l,
                ];
            }

            return ['ok' => true, 'message' => 'Fetched attendance logs.', 'logs' => $logs];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'logs' => []];
        }
    }
}

