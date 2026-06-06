<?php

namespace App\Services;

use App\Models\AttendanceDevice;
use App\Models\EmployeeDetail;
use App\Models\FingerprintDeviceUser;
use App\Models\FingerprintRawLog;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class FingerprintActivityLogService
{
    /**
     * @return array{
     *     days: array<int, array{date: string, scans: array<int, array<string, mixed>>}>,
     *     total_scans: int,
     *     range_from: string,
     *     range_to: string
     * }
     */
    public function buildGroupedDays(
        int $days = 60,
        ?int $deviceId = null,
        int $limit = 800
    ): array {
        $days = max(1, min(365, $days));
        $limit = max(10, min(2000, $limit));

        $from = now()->subDays($days - 1)->startOfDay();
        $to = now()->endOfDay();

        $query = FingerprintRawLog::query()->where(function ($q) use ($from, $to) {
            $q->whereBetween('scan_time', [$from, $to])
                ->orWhereBetween('created_at', [$from, $to]);
        });

        if ($deviceId !== null) {
            $query->where('attendance_device_id', $deviceId);
        }

        /** @var Collection<int, FingerprintRawLog> $logs */
        $logs = $query
            ->orderByRaw('COALESCE(created_at, scan_time) DESC')
            ->limit($limit)
            ->get();

        $deviceNames = AttendanceDevice::query()
            ->whereIn('id', $logs->pluck('attendance_device_id')->unique()->filter())
            ->pluck('name', 'id');

        $nameResolver = $this->buildNameResolver($logs, $deviceId);

        $byDate = [];
        foreach ($logs as $log) {
            $dateKey = $this->displayDateForLog($log);
            $pin = (string) $log->device_user_id;
            $devId = (int) $log->attendance_device_id;
            $scanAt = $log->scan_time ?? $log->created_at ?? now();

            $byDate[$dateKey][] = [
                'id' => (int) $log->id,
                'device_id' => $devId,
                'device_name' => (string) ($deviceNames[$devId] ?? ''),
                'device_user_id' => $pin,
                'employee_name' => $nameResolver($devId, $pin),
                'scan_time' => $scanAt->toIso8601String(),
                'action' => $this->actionFromStatus($log->status),
                'verify_type' => $log->verify_type,
                'status' => $log->status,
                'processing_status' => $log->processing_status,
                'processing_error' => $log->processing_error,
                'processed_at' => $log->processed_at?->toIso8601String(),
                'raw_kind' => is_array($log->raw_payload)
                    ? ($log->raw_payload['row']['_kind'] ?? $log->raw_payload['row']['_table'] ?? null)
                    : null,
            ];
        }

        krsort($byDate);

        $daysOut = [];
        foreach ($byDate as $date => $scans) {
            $daysOut[] = [
                'date' => $date,
                'scans' => array_values($scans),
            ];
        }

        return [
            'days' => $daysOut,
            'total_scans' => array_sum(array_map(fn (array $d) => count($d['scans']), $daysOut)),
            'range_from' => $from->toDateString(),
            'range_to' => $to->toDateString(),
        ];
    }

    /**
     * @param  Collection<int, FingerprintRawLog>  $logs
     */
    protected function buildNameResolver(Collection $logs, ?int $singleDeviceId): \Closure
    {
        $deviceIds = $singleDeviceId !== null
            ? collect([$singleDeviceId])
            : $logs->pluck('attendance_device_id')->unique()->filter();

        $fdUsers = FingerprintDeviceUser::query()
            ->whereIn('attendance_device_id', $deviceIds)
            ->with(['linkedEmployee.user:id,name'])
            ->get();

        $fduMap = [];
        foreach ($fdUsers as $u) {
            $fduMap[$u->attendance_device_id.'|'.$u->device_user_id] = $u;
        }

        $employeesByPin = EmployeeDetail::query()
            ->whereNotNull('device_user_id')
            ->with('user:id,name')
            ->get()
            ->keyBy(fn (EmployeeDetail $e) => (string) $e->device_user_id);

        return function (int $deviceId, string $pin) use ($fduMap, $employeesByPin): ?string {
            $key = $deviceId.'|'.$pin;
            $fdu = $fduMap[$key] ?? null;
            if ($fdu?->linkedEmployee?->user?->name) {
                return (string) $fdu->linkedEmployee->user->name;
            }

            $emp = $employeesByPin[$pin] ?? null;
            if ($emp?->user?->name) {
                return (string) $emp->user->name;
            }

            if ($fdu && filled($fdu->name)) {
                return (string) $fdu->name;
            }

            return null;
        };
    }

    protected function displayDateForLog(FingerprintRawLog $log): string
    {
        if ($log->scan_time) {
            $scan = Carbon::parse($log->scan_time);
            if ($scan->between(now()->subYear(), now()->addDay())) {
                return $scan->toDateString();
            }
        }

        return ($log->created_at ?? now())->toDateString();
    }

    protected function actionFromStatus(mixed $status): string
    {
        $s = trim((string) ($status ?? ''));

        return match ($s) {
            '0' => 'in',
            '1' => 'out',
            default => 'unknown',
        };
    }
}
