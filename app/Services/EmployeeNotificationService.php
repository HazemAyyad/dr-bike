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

    public function pushToEmployee(EmployeeDetail $employee, EmployeeNotification $notification): bool
    {
        $employee->loadMissing('user');
        $token = trim((string) ($employee->user->fcm_token ?? ''));
        if ($token === '' || $token === 'no_token') {
            Log::warning('Employee FCM skipped: no token', [
                'employee_id' => $employee->id,
                'notification_id' => $notification->id,
            ]);

            return false;
        }

        $payload = $this->buildFcmDataPayload($notification);

        $result = $this->firebaseService->sendToTokenQuietly(
            $token,
            $notification->title,
            $notification->body,
            $payload
        );

        return $result !== null;
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
     * @return array{
     *     employees:int,
     *     notified:int,
     *     fcm_sent:int,
     *     fcm_failed:int,
     *     in_app_only:int,
     *     skipped:int,
     *     skipped_no_tasks:int,
     *     skipped_already_sent:int,
     *     no_token:int,
     *     failed:int,
     *     details: list<array<string, mixed>>
     * }
     */
    public function sendDailyTaskReminders(bool $force = false): array
    {
        $stats = [
            'employees' => 0,
            'notified' => 0,
            'fcm_sent' => 0,
            'fcm_failed' => 0,
            'in_app_only' => 0,
            'skipped' => 0,
            'skipped_no_tasks' => 0,
            'skipped_already_sent' => 0,
            'no_token' => 0,
            'failed' => 0,
            'details' => [],
        ];

        $tz = 'Asia/Hebron';
        $dateKey = now()->timezone($tz)->toDateString();

        $employees = EmployeeDetail::query()
            ->with('user:id,name,fcm_token,type')
            ->whereHas('user', fn ($q) => $q->where('type', 'employee'))
            ->get();

        foreach ($employees as $employee) {
            $stats['employees']++;
            $employeeName = (string) ($employee->user->name ?? "موظف #{$employee->id}");
            $token = trim((string) ($employee->user->fcm_token ?? ''));
            $hasToken = $token !== '' && $token !== 'no_token';

            $tasks = EmployeePendingTasksForToday::visibleForEmployee((int) $employee->id);
            if ($tasks->isEmpty()) {
                $stats['skipped']++;
                $stats['skipped_no_tasks']++;
                $stats['details'][] = [
                    'employee_id' => $employee->id,
                    'name' => $employeeName,
                    'status' => 'skipped_no_tasks',
                    'tasks' => 0,
                ];

                continue;
            }

            $cacheKey = "employee_daily_task_reminder:{$employee->id}:{$dateKey}";
            if (! $force && Cache::has($cacheKey)) {
                $stats['skipped']++;
                $stats['skipped_already_sent']++;
                $stats['details'][] = [
                    'employee_id' => $employee->id,
                    'name' => $employeeName,
                    'status' => 'skipped_already_sent',
                    'tasks' => $tasks->count(),
                    'task_names' => $tasks->pluck('name')->filter()->values()->all(),
                ];

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
                if (! $hasToken) {
                    $stats['no_token']++;
                    $this->create(
                        $employee,
                        self::TYPE_DAILY_TASKS,
                        $title,
                        $body,
                        $data,
                        'employee_daily_reminder',
                        null,
                        false
                    );
                    $stats['notified']++;
                    $stats['in_app_only']++;
                    $stats['details'][] = [
                        'employee_id' => $employee->id,
                        'name' => $employeeName,
                        'status' => 'in_app_only_no_token',
                        'tasks' => $count,
                        'title' => $title,
                        'body' => $body,
                        'task_names' => $tasks->pluck('name')->filter()->values()->all(),
                    ];

                    continue;
                }

                $notification = EmployeeNotification::create([
                    'employee_id' => $employee->id,
                    'type' => self::TYPE_DAILY_TASKS,
                    'title' => $title,
                    'body' => $body,
                    'related_type' => 'employee_daily_reminder',
                    'related_id' => null,
                    'data' => $data,
                    'is_read' => false,
                ]);

                $fcmOk = $this->pushToEmployee($employee, $notification);
                $stats['notified']++;

                if ($fcmOk) {
                    $stats['fcm_sent']++;
                    Cache::put($cacheKey, true, now()->timezone($tz)->endOfDay());
                    $stats['details'][] = [
                        'employee_id' => $employee->id,
                        'name' => $employeeName,
                        'status' => 'fcm_sent',
                        'tasks' => $count,
                        'title' => $title,
                        'body' => $body,
                        'task_names' => $tasks->pluck('name')->filter()->values()->all(),
                        'notification_id' => $notification->id,
                    ];
                } else {
                    $stats['fcm_failed']++;
                    $stats['details'][] = [
                        'employee_id' => $employee->id,
                        'name' => $employeeName,
                        'status' => 'fcm_failed',
                        'tasks' => $count,
                        'title' => $title,
                        'body' => $body,
                        'task_names' => $tasks->pluck('name')->filter()->values()->all(),
                        'notification_id' => $notification->id,
                    ];
                }
            } catch (\Throwable $e) {
                $stats['failed']++;
                Log::error('Employee daily task notification failed', [
                    'employee_id' => $employee->id,
                    'error' => $e->getMessage(),
                ]);
                $stats['details'][] = [
                    'employee_id' => $employee->id,
                    'name' => $employeeName,
                    'status' => 'error',
                    'tasks' => $count,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $stats;
    }

    /**
     * @param  array<string, mixed>  $stats
     * @return array{text: string, report: array<string, mixed>}
     */
    public function formatDailyReminderReport(array $stats, bool $force = false): array
    {
        $tz = 'Asia/Hebron';
        $dateKey = now()->timezone($tz)->toDateString();

        $statusLabels = [
            'fcm_sent' => 'وصل إشعار الهاتف (FCM)',
            'fcm_failed' => 'فشل FCM — حُفظ داخل التطبيق فقط',
            'in_app_only_no_token' => 'لا توكن FCM — حُفظ داخل التطبيق فقط',
            'skipped_already_sent' => 'لم يُرسل — أُرسل له اليوم مسبقاً',
            'skipped_no_tasks' => 'لم يُرسل — لا مهام لليوم',
            'error' => 'خطأ أثناء الإرسال',
        ];

        $sent = [];
        $notSent = [];

        foreach ($stats['details'] ?? [] as $row) {
            $status = (string) ($row['status'] ?? '');
            $entry = [
                'employee_id' => $row['employee_id'] ?? null,
                'name' => $row['name'] ?? '?',
                'result' => $statusLabels[$status] ?? $status,
                'status' => $status,
                'tasks_count' => (int) ($row['tasks'] ?? 0),
                'task_names' => $row['task_names'] ?? [],
                'title' => $row['title'] ?? null,
                'body' => $row['body'] ?? null,
                'notification_id' => $row['notification_id'] ?? null,
                'error' => $row['error'] ?? null,
            ];

            if (in_array($status, ['fcm_sent', 'fcm_failed', 'in_app_only_no_token'], true)) {
                $sent[] = $entry;
            } else {
                $notSent[] = $entry;
            }
        }

        $report = [
            'type' => 'employee_daily_task_reminders',
            'date' => $dateKey,
            'timezone' => $tz,
            'force' => $force,
            'message_template' => [
                'title' => 'مهامك لليوم',
                'body_pattern' => 'لديك X مهام لليوم: أسماء المهام…',
            ],
            'summary' => [
                'total_employees' => $stats['employees'] ?? 0,
                'fcm_sent' => $stats['fcm_sent'] ?? 0,
                'fcm_failed' => $stats['fcm_failed'] ?? 0,
                'in_app_only' => $stats['in_app_only'] ?? 0,
                'skipped' => $stats['skipped'] ?? 0,
                'skipped_already_sent' => $stats['skipped_already_sent'] ?? 0,
                'skipped_no_tasks' => $stats['skipped_no_tasks'] ?? 0,
                'errors' => $stats['failed'] ?? 0,
            ],
            'sent' => $sent,
            'not_sent' => $notSent,
        ];

        $lines = [
            '══════════════════════════════════════',
            'تقرير تذكير مهام الموظفين — '.$dateKey,
            '══════════════════════════════════════',
            '',
            '■ ماذا يُرسل؟',
            '  العنوان: مهامك لليوم',
            '  النص: لديك (عدد) مهام لليوم + أسماء أول 3 مهام',
            '',
            '■ ملخص',
            sprintf('  موظفون: %d | وصل FCM: %d | فشل FCM: %d | داخل التطبيق فقط: %d',
                $report['summary']['total_employees'],
                $report['summary']['fcm_sent'],
                $report['summary']['fcm_failed'],
                $report['summary']['in_app_only'],
            ),
            sprintf('  لم يُرسل: %d (سبق اليوم: %d | بلا مهام: %d) | أخطاء: %d',
                $report['summary']['skipped'],
                $report['summary']['skipped_already_sent'],
                $report['summary']['skipped_no_tasks'],
                $report['summary']['errors'],
            ),
        ];

        if ($force) {
            $lines[] = '  وضع إعادة الإرسال (force): مفعّل';
        }

        $lines[] = '';
        $lines[] = '■ لمن أُرسل؟ ('.count($sent).')';

        if ($sent === []) {
            $lines[] = '  (لا أحد — لم يُنشأ إشعار جديد في هذا التشغيل)';
        }

        foreach ($sent as $i => $row) {
            $lines[] = sprintf(
                '  %d) %s (#%s)',
                $i + 1,
                $row['name'],
                $row['employee_id']
            );
            $lines[] = '     النتيجة: '.$row['result'];
            if ($row['title']) {
                $lines[] = '     العنوان: '.$row['title'];
            }
            if ($row['body']) {
                $lines[] = '     النص: '.$row['body'];
            }
            if (! empty($row['task_names'])) {
                $lines[] = '     المهام: '.implode('، ', $row['task_names']);
            }
            if ($row['notification_id']) {
                $lines[] = '     رقم الإشعار في النظام: #'.$row['notification_id'];
            }
        }

        $lines[] = '';
        $lines[] = '■ لم يُرسل ('.count($notSent).')';

        if ($notSent === []) {
            $lines[] = '  (لا أحد)';
        }

        foreach ($notSent as $i => $row) {
            $lines[] = sprintf(
                '  %d) %s (#%s) — %s',
                $i + 1,
                $row['name'],
                $row['employee_id'],
                $row['result']
            );
            if ($row['tasks_count'] > 0 && ! empty($row['task_names'])) {
                $lines[] = '     مهامه اليوم: '.implode('، ', $row['task_names']);
            }
        }

        return [
            'text' => implode("\n", $lines),
            'report' => $report,
        ];
    }
}
