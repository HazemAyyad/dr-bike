<?php

namespace App\Services;

use App\Models\EmployeeDetail;
use App\Models\EmployeeNotification;
use App\Models\EmployeeOrder;
use App\Models\SalaryPaymentItem;
use App\Support\EmployeeAttendanceToday;
use App\Support\EmployeePendingTasksForToday;
use App\Support\EmployeeWorkSchedule;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class EmployeeNotificationService
{
    public const TYPE_DAILY_TASKS = 'employee_daily_tasks';

    public const TYPE_HOURLY_REMINDER = 'employee_hourly_reminder';

    public const TYPE_DAILY_TASKS_COMPLETE = 'employee_daily_tasks_complete';

    public const TYPE_TASK_SCHEDULED_REMINDER = 'employee_task_scheduled_reminder';

    public const TYPE_OPERATIONAL_REMINDER = 'employee_operational_reminder';

    public const TYPE_SALES_DAILY_CLOSING_APPROVED = 'sales_daily_closing_approved';

    public const TYPE_SALES_DAILY_CLOSING_REJECTED = 'sales_daily_closing_rejected';

    public const TYPE_SALES_DAILY_REOPEN_APPROVED = 'sales_daily_reopen_approved';

    public const TYPE_SALES_DAILY_REOPEN_REJECTED = 'sales_daily_reopen_rejected';

    public const TYPE_SALES_DAILY_EXTERNAL_SALE = 'sales_daily_external_sale';

    public const TYPE_SALES_DAILY_PREVIOUS_DAY_OPEN = 'sales_daily_previous_day_open';

    public const TYPE_MAINTENANCE_DAILY_CLOSING_APPROVED = 'maintenance_daily_closing_approved';

    public const TYPE_MAINTENANCE_DAILY_CLOSING_REJECTED = 'maintenance_daily_closing_rejected';

    public const TYPE_MAINTENANCE_DAILY_PREVIOUS_DAY_OPEN = 'maintenance_daily_previous_day_open';

    public const TYPE_SUPPORT_MESSAGE = 'support_message';
    public const TYPE_NEGATIVE_SALES_ORDER_STOCK = 'negative_sales_order_stock';

    public const TYPE_EMPLOYEE_LOAN_APPROVED = 'employee_loan_approved';

    public const TYPE_EMPLOYEE_LOAN_REJECTED = 'employee_loan_rejected';

    public const TYPE_EMPLOYEE_LOAN_CANCELLED = 'employee_loan_cancelled';

    public const TYPE_EMPLOYEE_LOAN_UPDATED = 'employee_loan_updated';

    public const TYPE_NOTE_SHARED = 'note_shared';

    public const TYPE_NOTE_REMINDER = 'note_reminder';

    public const TYPE_EMPLOYEE_POINTS_CHANGED = 'employee_points_changed';

    public const TYPE_EMPLOYEE_REWARD_EARNED = 'employee_reward_earned';

    public const TYPE_GOAL_DAILY_SUMMARY = 'goal_daily_summary';

    public const TYPE_GOAL_NO_PROGRESS = 'goal_no_progress';

    public const TYPE_GOAL_SHARED = 'goal_shared';

    public const TYPE_SALARY_PAID = 'salary_paid';

    public function __construct(
        protected FirebaseService $firebaseService
    ) {}

    /**
     * Employee push/in-app notifications are always Arabic.
     *
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    private function withArabicLocale(callable $callback): mixed
    {
        $previous = App::getLocale();
        App::setLocale('ar');

        try {
            return $callback();
        } finally {
            App::setLocale($previous);
        }
    }

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
    ): ?EmployeeNotification {
        if ((bool) ($employee->is_suspended ?? false)) {
            Log::info('Employee notification skipped: employee suspended', [
                'employee_id' => $employee->id,
                'type' => $type,
                'related_type' => $relatedType,
                'related_id' => $relatedId,
            ]);

            return null;
        }

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
        if ((bool) ($employee->is_suspended ?? false)) {
            Log::info('Employee FCM skipped: employee suspended', [
                'employee_id' => $employee->id,
                'notification_id' => $notification->id,
            ]);

            return false;
        }

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

    public function notifyLoanApproved(EmployeeOrder $order): ?EmployeeNotification
    {
        return $this->withArabicLocale(function () use ($order) {
            $order->loadMissing('employee.user');
            $employee = $order->employee;
            if (! $employee) {
                return null;
            }

            $amount = number_format((float) ($order->loan_value ?? 0), 2, '.', '');

            return $this->create(
                $employee,
                self::TYPE_EMPLOYEE_LOAN_APPROVED,
                'تم قبول طلب السلفة',
                "تم قبول طلب السلفة بقيمة {$amount}",
                [
                    'employee_order_id' => (string) $order->id,
                    'loan_value' => $amount,
                    'status' => 'approved',
                    'reviewed_at' => now()->toIso8601String(),
                ],
                'employee_order',
                (int) $order->id,
                true
            );
        });
    }

    public function notifySalaryPaid(SalaryPaymentItem $item): ?EmployeeNotification
    {
        return $this->withArabicLocale(function () use ($item) {
            $item->loadMissing(['employee.user', 'salaryPeriod']);
            if (! $item->employee) {
                return null;
            }

            $amount = number_format((float) $item->amount_paid, 2, '.', '');
            $month = optional($item->salaryPeriod?->salary_month)->format('Y-m');
            $isSettlementOnly = (float) $item->amount_paid <= 0;

            return $this->create(
                $item->employee,
                self::TYPE_SALARY_PAID,
                $isSettlementOnly ? 'تمت تسوية راتبك' : 'تم صرف راتبك',
                $isSettlementOnly
                    ? "تمت تسوية راتب شهر {$month} بالكامل مقابل السلف المستحقة. يرجى مراجعة السند والتوقيع."
                    : "تم صرف مبلغ {$amount} شيكل عن راتب شهر {$month}. يرجى تأكيد الاستلام والتوقيع.",
                [
                    'salary_payment_item_id' => (string) $item->id,
                    'salary_month' => (string) $month,
                    'amount_paid' => $amount,
                    'receipt_status' => 'pending',
                    'requires_acknowledgment' => '1',
                ],
                'salary_payment_item',
                (int) $item->id,
                true
            );
        });
    }

    public function notifyLoanRejected(EmployeeOrder $order, ?string $reason = null): ?EmployeeNotification
    {
        return $this->withArabicLocale(function () use ($order, $reason) {
            $order->loadMissing('employee.user');
            $employee = $order->employee;
            if (! $employee) {
                return null;
            }

            $amount = number_format((float) ($order->loan_value ?? 0), 2, '.', '');
            $reason = trim((string) ($reason ?? $order->rejection_reason ?? ''));
            $reasonLabel = $reason !== '' ? $reason : 'لم يتم توضيح السبب';

            return $this->create(
                $employee,
                self::TYPE_EMPLOYEE_LOAN_REJECTED,
                'تم رفض طلب السلفة',
                "تم رفض طلب السلفة بقيمة {$amount}. السبب: {$reasonLabel}",
                [
                    'employee_order_id' => (string) $order->id,
                    'loan_value' => $amount,
                    'status' => 'rejected',
                    'rejection_reason' => $reasonLabel,
                    'reviewed_at' => now()->toIso8601String(),
                ],
                'employee_order',
                (int) $order->id,
                true
            );
        });
    }

    public function notifyLoanCancelled(EmployeeOrder $order, ?string $reason = null): ?EmployeeNotification
    {
        return $this->withArabicLocale(function () use ($order, $reason) {
            $order->loadMissing('employee.user');
            $employee = $order->employee;
            if (! $employee) {
                return null;
            }

            $amount = number_format((float) ($order->loan_value ?? 0), 2, '.', '');
            $reason = trim((string) ($reason ?? $order->cancellation_reason ?? ''));
            $reasonLabel = $reason !== '' ? $reason : 'لم يتم توضيح السبب';

            return $this->create(
                $employee,
                self::TYPE_EMPLOYEE_LOAN_CANCELLED,
                'تم إلغاء السلفة',
                "تم إلغاء السلفة بقيمة {$amount}. السبب: {$reasonLabel}",
                [
                    'employee_order_id' => (string) $order->id,
                    'loan_value' => $amount,
                    'status' => 'cancelled',
                    'cancellation_reason' => $reasonLabel,
                    'reviewed_at' => now()->toIso8601String(),
                ],
                'employee_order',
                (int) $order->id,
                true
            );
        });
    }

    public function notifyLoanUpdated(EmployeeOrder $order, float $oldAmount, float $newAmount): ?EmployeeNotification
    {
        return $this->withArabicLocale(function () use ($order, $oldAmount, $newAmount) {
            $order->loadMissing('employee.user');
            $employee = $order->employee;
            if (! $employee) {
                return null;
            }

            $old = number_format($oldAmount, 2, '.', '');
            $new = number_format($newAmount, 2, '.', '');

            return $this->create(
                $employee,
                self::TYPE_EMPLOYEE_LOAN_UPDATED,
                'تم تعديل السلفة',
                "تم تعديل السلفة من {$old} إلى {$new}",
                [
                    'employee_order_id' => (string) $order->id,
                    'old_loan_value' => $old,
                    'loan_value' => $new,
                    'status' => 'approved',
                    'reviewed_at' => now()->toIso8601String(),
                ],
                'employee_order',
                (int) $order->id,
                true
            );
        });
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

            if (! EmployeeWorkSchedule::isWithin($employee)) {
                $stats['skipped']++;
                $stats['details'][] = [
                    'employee_id' => $employee->id,
                    'name' => $employeeName,
                    'status' => 'skipped_outside_work_schedule',
                    'tasks' => 0,
                ];

                continue;
            }

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
            'skipped_outside_work_schedule' => 'لم يُرسل — خارج وقت دوام الموظف',
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

    /**
     * Hourly push/in-app: pending tasks and/or missing attendance check-in.
     *
     * @return array<string, mixed>
     */
    public function sendHourlyReminders(bool $force = false): array
    {
        return $this->withArabicLocale(fn () => $this->sendHourlyRemindersArabic($force));
    }

    /**
     * @return array<string, mixed>
     */
    private function sendHourlyRemindersArabic(bool $force = false): array
    {
        $tz = EmployeePendingTasksForToday::TIMEZONE;
        $now = now()->timezone($tz);
        $dateKey = $now->toDateString();
        $hourKey = $now->format('G');

        $stats = [
            'employees' => 0,
            'notified' => 0,
            'fcm_sent' => 0,
            'skipped' => 0,
            'skipped_hour' => 0,
            'skipped_nothing' => 0,
            'failed' => 0,
        ];

        $employees = EmployeeDetail::query()
            ->with('user:id,name,fcm_token,type')
            ->whereHas('user', fn ($q) => $q->where('type', 'employee'))
            ->get();

        foreach ($employees as $employee) {
            $stats['employees']++;

            if (! EmployeeWorkSchedule::isWithin($employee, $now)) {
                $stats['skipped']++;
                $stats['skipped_nothing']++;

                continue;
            }

            $pending = EmployeePendingTasksForToday::pendingActionForEmployee((int) $employee->id);
            $needsAttendance = ! EmployeeAttendanceToday::hasCheckedInToday((int) $employee->id);

            if ($pending->isEmpty() && ! $needsAttendance) {
                $stats['skipped']++;
                $stats['skipped_nothing']++;

                continue;
            }

            $cacheKey = "employee_hourly_reminder:{$employee->id}:{$dateKey}:{$hourKey}";
            if (! $force && Cache::has($cacheKey)) {
                $stats['skipped']++;
                $stats['skipped_hour']++;

                continue;
            }

            [$title, $body, $data] = $this->buildHourlyReminderMessage($pending, $needsAttendance, $dateKey);

            try {
                $this->create(
                    $employee,
                    self::TYPE_HOURLY_REMINDER,
                    $title,
                    $body,
                    $data,
                    'employee_hourly_reminder',
                    null,
                    true
                );
                $stats['notified']++;
                $stats['fcm_sent']++;
                Cache::put($cacheKey, true, $now->copy()->addHour());
            } catch (\Throwable $e) {
                $stats['failed']++;
                Log::error('Employee hourly reminder failed', [
                    'employee_id' => $employee->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $stats;
    }

    /**
     * @return array{0: string, 1: string, 2: array<string, string>}
     */
    private function buildHourlyReminderMessage(
        \Illuminate\Support\Collection $pending,
        bool $needsAttendance,
        string $dateKey
    ): array {
        $count = $pending->count();
        $names = $pending->take(3)->map(fn ($t) => $t->name ?? '')->filter()->values();

        if ($count > 0 && $needsAttendance) {
            $title = __('messages.employee_hourly_reminder_tasks_attendance_title');
            $body = $count === 1
                ? __('messages.employee_hourly_reminder_tasks_attendance_body_one')
                : __('messages.employee_hourly_reminder_tasks_attendance_body', ['count' => $count]);
        } elseif ($count > 0) {
            $title = __('messages.employee_hourly_reminder_tasks_title');
            $body = $count === 1
                ? __('messages.employee_hourly_reminder_tasks_body_one')
                : __('messages.employee_hourly_reminder_tasks_body', ['count' => $count]);
        } else {
            $title = __('messages.employee_hourly_reminder_attendance_title');
            $body = __('messages.employee_hourly_reminder_attendance_body');
        }

        if ($names->isNotEmpty()) {
            $body .= ': '.$names->implode('، ');
            if ($count > 3) {
                $body .= '…';
            }
        }

        return [
            $title,
            $body,
            [
                'date' => $dateKey,
                'task_count' => (string) $count,
                'needs_attendance' => $needsAttendance ? '1' : '0',
            ],
        ];
    }

    public function notifyTaskScheduledReminder(
        EmployeeDetail $employee,
        string $taskName,
        int $minutesBefore,
        \Carbon\Carbon $start,
        string $kind,
        int $relatedId
    ): void {
        $this->withArabicLocale(function () use ($employee, $taskName, $minutesBefore, $start, $kind, $relatedId) {
            $timeLabel = $start->timezone(EmployeePendingTasksForToday::TIMEZONE)->format('Y-m-d H:i');
            $body = $minutesBefore <= 0
                ? __('messages.employee_task_reminder_body_at_time', [
                    'name' => $taskName,
                    'time' => $timeLabel,
                ])
                : __('messages.employee_task_reminder_body_before', [
                    'name' => $taskName,
                    'time' => $timeLabel,
                ]);

            try {
                $this->create(
                    $employee,
                    self::TYPE_TASK_SCHEDULED_REMINDER,
                    __('messages.employee_task_reminder_title'),
                    $body,
                    [
                        'task_name' => $taskName,
                        'reminder_kind' => $kind,
                    ],
                    $kind === 'occ' ? 'employee_task_occurrence' : 'employee_task',
                    $relatedId,
                    true
                );
            } catch (\Throwable $e) {
                Log::error('Employee task scheduled reminder failed', [
                    'employee_id' => $employee->id,
                    'task' => $taskName,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    public function maybeNotifyAllDailyTasksCompleted(EmployeeDetail $employee): void
    {
        $this->withArabicLocale(function () use ($employee) {
            $this->maybeNotifyAllDailyTasksCompletedArabic($employee);
        });
    }

    private function maybeNotifyAllDailyTasksCompletedArabic(EmployeeDetail $employee): void
    {
        if (! EmployeePendingTasksForToday::allVisibleTodayFinishedByEmployee((int) $employee->id)) {
            return;
        }

        $tz = EmployeePendingTasksForToday::TIMEZONE;
        $dateKey = now()->timezone($tz)->toDateString();
        $cacheKey = "employee_daily_tasks_congrats:{$employee->id}:{$dateKey}";

        if (Cache::has($cacheKey)) {
            return;
        }

        $count = EmployeePendingTasksForToday::allVisibleForEmployeeToday((int) $employee->id)->count();

        try {
            $this->create(
                $employee,
                self::TYPE_DAILY_TASKS_COMPLETE,
                __('messages.employee_daily_tasks_complete_title'),
                __('messages.employee_daily_tasks_complete_body', ['count' => $count]),
                [
                    'date' => $dateKey,
                    'task_count' => (string) $count,
                ],
                'employee_daily_tasks_complete',
                null,
                true
            );
            Cache::put($cacheKey, true, now()->timezone($tz)->endOfDay());
        } catch (\Throwable $e) {
            Log::error('Employee daily tasks complete notification failed', [
                'employee_id' => $employee->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
