<?php

namespace App\Services;

use App\Models\EmployeeDetail;
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

            $token = trim((string) ($employee->user->fcm_token ?? ''));
            if ($token === '') {
                $stats['no_token']++;

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
                'type' => self::TYPE_DAILY_TASKS,
                'employee_id' => (string) $employee->id,
                'task_count' => (string) $count,
                'date' => $dateKey,
            ];

            $sent = $this->firebaseService->sendToTokenQuietly($token, $title, $body, $data);
            if ($sent !== null) {
                Cache::put($cacheKey, true, now()->timezone($tz)->endOfDay());
                $stats['notified']++;
                Log::info('Employee daily task FCM sent', [
                    'employee_id' => $employee->id,
                    'task_count' => $count,
                ]);
            } else {
                $stats['failed']++;
                Log::warning('Employee daily task FCM failed', [
                    'employee_id' => $employee->id,
                ]);
            }
        }

        return $stats;
    }
}
