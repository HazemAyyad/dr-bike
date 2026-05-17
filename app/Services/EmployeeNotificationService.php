<?php

namespace App\Services;

use App\Models\EmployeeDetail;
use App\Models\EmployeeNotification;
use App\Support\EmployeePendingTasksForToday;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class EmployeeNotificationService
{
    public const TYPE_DAILY_TASKS = 'employee_daily_tasks';

    public function __construct(
        protected FirebaseService $firebaseService
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(
        EmployeeDetail $employee,
        string $type,
        string $title,
        string $body,
        array $data = [],
        ?string $relatedType = null,
        ?int $relatedId = null,
        bool $sendPush = true
    ): EmployeeNotification {
        $notification = EmployeeNotification::create([
            'employee_id' => $employee->id,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'related_type' => $relatedType,
            'related_id' => $relatedId,
            'data' => $data,
            'is_read' => false,
        ]);

        if ($sendPush) {
            $this->pushToEmployee($employee, $notification);
        }

        return $notification;
    }

    public function pushToEmployee(EmployeeDetail $employee, EmployeeNotification $notification): void
    {
        $employee->loadMissing('user');
        $token = trim((string) ($employee->user->fcm_token ?? ''));
        if ($token === '' || $token === 'no_token') {
            Log::warning('Employee FCM skipped: no token', [
                'employee_id' => $employee->id,
                'notification_id' => $notification->id,
            ]);

            return;
        }

        $payload = $this->buildFcmDataPayload($notification);

        $this->firebaseService->sendToTokenQuietly(
            $token,
            $notification->title,
            $notification->body,
            $payload
        );
    }

    /**
     * @return array<string, string>
     */
    public function buildFcmDataPayload(EmployeeNotification $notification): array
    {
        $row = $notification->fresh();

        return array_merge($row->data ?? [], [
            'notification_id' => (string) $row->id,
            'type' => (string) $row->type,
            'related_type' => (string) ($row->related_type ?? ''),
            'related_id' => (string) ($row->related_id ?? ''),
            'employee_id' => (string) $row->employee_id,
        ]);
    }

    /**
     * @return array{employees:int,notified:int,skipped:int,no_token:int,failed:int}
     */
    public function sendDailyTaskReminders(bool $force = false): array
    {
        $stats = [
            'employees' => 0,
            'notified' => 0,
            'skipped' => 0,
            'no_token' => 0,
            'failed' => 0,
        ];

        $tz = 'Asia/Hebron';
        $dateKey = now()->timezone($tz)->toDateString();

        $employees = EmployeeDetail::query()
            ->with('user:id,name,fcm_token,type')
            ->whereHas('user', fn ($q) => $q->where('type', 'employee'))
            ->get();

        foreach ($employees as $employee) {
            $stats['employees']++;

            $tasks = EmployeePendingTasksForToday::visibleForEmployee((int) $employee->id);
            if ($tasks->isEmpty()) {
                $stats['skipped']++;

                continue;
            }

            $cacheKey = "employee_daily_task_reminder:{$employee->id}:{$dateKey}";
            if (! $force && Cache::has($cacheKey)) {
                $stats['skipped']++;

                continue;
            }

            $count = $tasks->count();
            $title = 'مهامك لليوم';
            $body = $count === 1
                ? 'لديك مهمة واحدة لليوم'
                : "لديك {$count} مهام لليوم";

            $names = $tasks->take(3)->pluck('name')->filter()->values();
            if ($names->isNotEmpty()) {
                $body .= ': '.$names->implode('، ');
                if ($count > 3) {
                    $body .= '…';
                }
            }

            $data = [
                'task_count' => (string) $count,
                'date' => $dateKey,
            ];

            try {
                $this->create(
                    $employee,
                    self::TYPE_DAILY_TASKS,
                    $title,
                    $body,
                    $data,
                    'employee_daily_reminder',
                    null,
                    true
                );
                Cache::put($cacheKey, true, now()->timezone($tz)->endOfDay());
                $stats['notified']++;
            } catch (\Throwable $e) {
                $stats['failed']++;
                Log::error('Employee daily task notification failed', [
                    'employee_id' => $employee->id,
                    'error' => $e->getMessage(),
                ]);
                $token = trim((string) ($employee->user->fcm_token ?? ''));
                if ($token === '' || $token === 'no_token') {
                    $stats['no_token']++;
                }
            }
        }

        return $stats;
    }
}
