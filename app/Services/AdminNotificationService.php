<?php

namespace App\Services;

use App\Models\AdminDeviceToken;
use App\Models\AdminNotification;
use App\Models\EmployeeDetail;
use App\Models\EmployeeOrder;
use App\Models\EmployeeSubTask;
use App\Models\EmployeeTask;
use App\Models\EmployeeTaskOccurrence;
use App\Models\EmployeeTaskOccurrenceSubtask;
use App\Models\IncomingCheck;
use App\Models\OutgoingCheck;
use App\Models\Store\StoreSalesOrder;
use App\Models\StockImageExport;
use App\Support\EmployeePendingTasksForToday;
use Carbon\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;

class AdminNotificationService
{
    public function __construct(
        protected FirebaseService $firebaseService
    ) {}

    public const TYPE_EMPLOYEE_LOGIN = 'employee_login';

    public const TYPE_EMPLOYEE_LOGOUT = 'employee_logout';

    public const TYPE_EMPLOYEE_TASK_COMPLETED = 'employee_task_completed';

    public const TYPE_EMPLOYEE_TASK_SUBMITTED = 'employee_task_submitted';

    public const TYPE_EMPLOYEE_SUBTASK_COMPLETED = 'employee_subtask_completed';

    public const TYPE_EMPLOYEE_LOGOUT_PENDING_TASKS = 'employee_logout_pending_tasks';

    public const TYPE_CHECK_DUE_REMINDER = 'check_due_reminder';

    public const TYPE_CHECK_CASHED = 'check_cashed';

    public const TYPE_CHECK_RETURNED = 'check_returned';

    public const TYPE_SALES_DAILY_CLOSING_REQUEST = 'sales_daily_closing_request';

    public const TYPE_SALES_CANCELLATION_REQUEST = 'sales_cancellation_request';

    public const TYPE_SALES_DAILY_REOPEN_REQUEST = 'sales_daily_reopen_request';

    public const TYPE_SALES_DAILY_EXTERNAL_SALE = 'sales_daily_external_sale';

    public const TYPE_SALES_DAILY_PREVIOUS_DAY_OPEN = 'sales_daily_previous_day_open';

    public const TYPE_SUSPENDED_INSTANT_SALE_CREATED = 'suspended_instant_sale_created';

    public const TYPE_SUSPENDED_INSTANT_SALE_COMPLETED = 'suspended_instant_sale_completed';

    public const TYPE_ATTENDANCE_AUTO_CHECKOUT = 'attendance_auto_checkout';

    public const TYPE_ATTENDANCE_ABSENT_REMINDER = 'attendance_absent_reminder';

    public const TYPE_ATTENDANCE_OVERTIME_REQUEST = 'attendance_overtime_request';

    public const TYPE_EMPLOYEE_LOAN_REQUEST = 'employee_loan_request';

    public const TYPE_STORE_USER_REGISTERED = 'store_user_registered';

    public const TYPE_STORE_ORDER_CREATED = 'store_order_created';

    public const TYPE_STORE_ORDER_CANCELED = 'store_order_canceled';

    public const TYPE_SUPPORT_MESSAGE = 'support_message';

    public const TYPE_NEGATIVE_INSTANT_SALE_STOCK = 'negative_instant_sale_stock';

    public const TYPE_APP_DEVELOPMENT_TASK = 'app_development_task';

    public const TYPE_PASSWORD_RESET_OTP = 'password_reset_otp';

    public const TYPE_NOTE_SHARED = 'note_shared';

    public const TYPE_NOTE_REMINDER = 'note_reminder';

    public const TYPE_STOCK_IMAGES_EXPORT_READY = 'stock_images_export_ready';

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(
        string $type,
        string $title,
        string $body,
        array $data = [],
        ?int $employeeId = null,
        ?string $relatedType = null,
        ?int $relatedId = null,
        bool $sendPush = true,
        ?int $recipientUserId = null
    ): AdminNotification {
        $notification = AdminNotification::create([
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'employee_id' => $employeeId,
            'recipient_user_id' => $recipientUserId,
            'related_type' => $relatedType,
            'related_id' => $relatedId,
            'data' => $data,
            'is_read' => false,
        ]);

        if ($sendPush) {
            $this->pushToAdminDevices($notification);
        }

        return $notification;
    }

    public function notifyEmployeeLogin(
        EmployeeDetail $employee,
        ?int $attendanceId = null,
        string $source = 'qr',
        ?string $loginTime = null
    ): AdminNotification {
        return $this->withArabicLocale(function () use ($employee, $attendanceId, $source, $loginTime) {
            return $this->notifyEmployeeLoginLocalized($employee, $attendanceId, $source, $loginTime);
        });
    }

    protected function notifyEmployeeLoginLocalized(
        EmployeeDetail $employee,
        ?int $attendanceId,
        string $source,
        ?string $loginTime
    ): AdminNotification {
        $employee->loadMissing('user');
        $name = $employee->user->name ?? __('messages.employee_default_name');
        $time = $this->formatNotificationTime($loginTime);
        $sourceLabel = $this->attendanceSourceLabel($source);

        $data = [
            'employee_id' => (string) $employee->id,
            'employee_name' => $name,
            'login_time' => $time,
            'attendance_id' => $attendanceId !== null ? (string) $attendanceId : '',
            'source' => $source,
        ];

        return $this->create(
            self::TYPE_EMPLOYEE_LOGIN,
            __('messages.admin_notify_login_title'),
            __('messages.admin_notify_login_body', [
                'employee' => $name,
                'source' => $sourceLabel,
                'time' => $time,
            ]),
            $data,
            $employee->id,
            $attendanceId !== null ? 'employee_attendance' : null,
            $attendanceId,
            true
        );
    }

    public function notifyEmployeeLogout(
        EmployeeDetail $employee,
        ?int $attendanceId,
        ?string $logoutTime = null,
        string $source = 'qr',
        bool $isReverseCheckout = false
    ): AdminNotification {
        return $this->withArabicLocale(function () use ($employee, $attendanceId, $logoutTime, $source, $isReverseCheckout) {
            return $this->notifyEmployeeLogoutLocalized(
                $employee,
                $attendanceId,
                $logoutTime,
                $source,
                $isReverseCheckout
            );
        });
    }

    protected function notifyEmployeeLogoutLocalized(
        EmployeeDetail $employee,
        ?int $attendanceId,
        ?string $logoutTime,
        string $source,
        bool $isReverseCheckout
    ): AdminNotification {
        $employee->loadMissing('user');
        $name = $employee->user->name ?? __('messages.employee_default_name');
        $time = $this->formatNotificationTime($logoutTime);
        $sourceLabel = $this->attendanceSourceLabel($source);
        $reverseLabel = $isReverseCheckout
            ? __('messages.admin_notify_reverse_checkout_suffix')
            : '';

        $data = [
            'employee_id' => (string) $employee->id,
            'employee_name' => $name,
            'logout_time' => $time,
            'attendance_id' => $attendanceId !== null ? (string) $attendanceId : '',
            'source' => $source,
            'is_reverse_checkout' => $isReverseCheckout ? '1' : '0',
        ];

        return $this->create(
            self::TYPE_EMPLOYEE_LOGOUT,
            __('messages.admin_notify_logout_title'),
            __('messages.admin_notify_logout_body', [
                'employee' => $name,
                'source' => $sourceLabel,
                'time' => $time,
                'reverse' => $reverseLabel,
            ]),
            $data,
            $employee->id,
            $attendanceId !== null ? 'employee_attendance' : null,
            $attendanceId,
            true
        );
    }

    /**
     * @param  list<array{employee_id: int, employee_name: string}>  $closedEmployees
     */
    public function notifyAutoCheckoutSummary(string $workDate, array $closedEmployees): ?AdminNotification
    {
        if ($closedEmployees === []) {
            return null;
        }

        return $this->withArabicLocale(function () use ($workDate, $closedEmployees) {
            $names = collect($closedEmployees)->pluck('employee_name')->implode('، ');
            $count = count($closedEmployees);
            $dateLabel = Carbon::parse($workDate, EmployeePendingTasksForToday::TIMEZONE)
                ->locale('ar')
                ->translatedFormat('l j F Y');

            return $this->create(
                self::TYPE_ATTENDANCE_AUTO_CHECKOUT,
                __('messages.admin_notify_auto_checkout_title'),
                __('messages.admin_notify_auto_checkout_body', [
                    'count' => (string) $count,
                    'names' => $names,
                    'date' => $dateLabel,
                ]),
                [
                    'work_date' => $workDate,
                    'employee_count' => (string) $count,
                    'employees' => $closedEmployees,
                ],
                null,
                'attendance_auto_checkout',
                null,
                true
            );
        });
    }

    /**
     * @param  list<array{employee_id: int, employee_name: string}>  $absentEmployees
     */
    public function notifyAbsentEmployeesReminder(
        string $workDate,
        array $absentEmployees,
        bool $force = false
    ): ?AdminNotification {
        if ($absentEmployees === [] || (! $force && $this->hasAbsentReminderForDate($workDate))) {
            return null;
        }

        return $this->withArabicLocale(function () use ($workDate, $absentEmployees) {
            $names = collect($absentEmployees)->pluck('employee_name')->implode('، ');
            $count = count($absentEmployees);
            $timeLabel = now(EmployeePendingTasksForToday::TIMEZONE)
                ->locale('ar')
                ->translatedFormat('g:i a');

            return $this->create(
                self::TYPE_ATTENDANCE_ABSENT_REMINDER,
                __('messages.admin_notify_absent_title'),
                __('messages.admin_notify_absent_body', [
                    'count' => (string) $count,
                    'names' => $names,
                    'time' => $timeLabel,
                ]),
                [
                    'work_date' => $workDate,
                    'employee_count' => (string) $count,
                    'employees' => $absentEmployees,
                    'checked_at' => now(EmployeePendingTasksForToday::TIMEZONE)->toIso8601String(),
                ],
                null,
                'attendance_absent_reminder',
                null,
                true
            );
        });
    }

    public function notifyAttendanceOvertimePending(
        EmployeeDetail $employee,
        \App\Models\EmployeeAttendanceOvertimeRequest $request
    ): AdminNotification {
        return $this->withArabicLocale(function () use ($employee, $request) {
            $employee->loadMissing('user');
            $name = $employee->user->name ?? __('messages.employee_default_name');
            $minutes = (int) $request->requested_minutes;
            $hours = number_format($minutes / 60, 2);
            $date = $request->work_date?->toDateString() ?? '';

            return $this->create(
                self::TYPE_ATTENDANCE_OVERTIME_REQUEST,
                __('messages.admin_notify_attendance_overtime_title'),
                __('messages.admin_notify_attendance_overtime_body', [
                    'employee' => $name,
                    'hours' => $hours,
                    'date' => $date,
                ]),
                [
                    'request_id' => (string) $request->id,
                    'employee_id' => (string) $employee->id,
                    'employee_name' => $name,
                    'work_date' => $date,
                    'requested_minutes' => (string) $minutes,
                    'checkout_source' => (string) ($request->checkout_source ?? ''),
                ],
                $employee->id,
                'attendance_overtime_request',
                (int) $request->id,
                true
            );
        });
    }

    public function notifyEmployeeLoanRequest(EmployeeOrder $order): AdminNotification
    {
        return $this->withArabicLocale(function () use ($order) {
            $order->loadMissing('employee.user');
            $employee = $order->employee;
            $name = $employee?->user?->name ?? __('messages.employee_default_name');
            $amount = number_format((float) ($order->loan_value ?? 0), 2, '.', '');

            return $this->create(
                self::TYPE_EMPLOYEE_LOAN_REQUEST,
                'طلب سلفة جديد',
                "الموظف {$name} طلب سلفة بقيمة {$amount}",
                [
                    'employee_order_id' => (string) $order->id,
                    'employee_id' => (string) ($employee?->id ?? ''),
                    'employee_name' => $name,
                    'loan_value' => $amount,
                    'status' => (string) ($order->status ?? 'pending'),
                    'requested_at' => optional($order->created_at)->toIso8601String() ?? now()->toIso8601String(),
                ],
                $employee?->id,
                'employee_order',
                (int) $order->id,
                true
            );
        });
    }

    public function notifyStoreUserRegistered(\Illuminate\Foundation\Auth\User $user): AdminNotification
    {
        return $this->withArabicLocale(function () use ($user) {
            $name = $user->name ?: $user->email;
            $email = (string) $user->email;
            $phone = (string) ($user->phone ?? '');

            return $this->create(
                self::TYPE_STORE_USER_REGISTERED,
                __('messages.admin_notify_store_user_registered_title'),
                __('messages.admin_notify_store_user_registered_body', [
                    'user' => $name,
                    'email' => $email,
                    'phone' => $phone,
                ]),
                [
                    'user_id' => (string) $user->id,
                    'user_name' => (string) $name,
                    'email' => $email,
                    'phone' => $phone,
                    'registered_at' => now()->toIso8601String(),
                    'source' => 'store',
                ],
                null,
                'store_user',
                (int) $user->id,
                true
            );
        });
    }

    public function notifyStoreOrderCreated(StoreSalesOrder $order): AdminNotification
    {
        return $this->withArabicLocale(function () use ($order) {
            $serial = (string) ($order->serial_number ?: $order->id);
            $customer = (string) ($order->customer_name ?: __('messages.sales_order_unknown_customer'));
            $phone = (string) ($order->customer_phone ?? '');
            $city = (string) ($order->shiply_city_name ?? '');
            $total = number_format((float) ($order->total ?? 0), 2);

            return $this->create(
                self::TYPE_STORE_ORDER_CREATED,
                __('messages.admin_notify_store_order_created_title', [
                    'serial' => $serial,
                ]),
                __('messages.admin_notify_store_order_created_body', [
                    'serial' => $serial,
                    'customer' => $customer,
                    'phone' => $phone,
                    'city' => $city,
                    'total' => $total,
                ]),
                [
                    'order_id' => (string) $order->id,
                    'serial' => $serial,
                    'customer_name' => $customer,
                    'phone' => $phone,
                    'city' => $city,
                    'total' => $total,
                    'source' => 'store',
                    'created_at' => now()->toIso8601String(),
                ],
                null,
                'store_order',
                (int) $order->id,
                true
            );
        });
    }

    public function notifyStoreOrderCanceled(StoreSalesOrder $order): AdminNotification
    {
        return $this->withArabicLocale(function () use ($order) {
            $serial = (string) ($order->serial_number ?: $order->id);
            $customer = (string) ($order->customer_name ?: __('messages.sales_order_unknown_customer'));
            $phone = (string) ($order->customer_phone ?? '');

            return $this->create(
                self::TYPE_STORE_ORDER_CANCELED,
                'إلغاء طلب متجر #'.$serial,
                'تم إلغاء طلب المتجر #'.$serial.' للزبون '.$customer.($phone !== '' ? ' - '.$phone : ''),
                [
                    'order_id' => (string) $order->id,
                    'serial' => $serial,
                    'customer_name' => $customer,
                    'phone' => $phone,
                    'source' => 'store',
                    'status' => 'canceled',
                    'canceled_at' => now()->toIso8601String(),
                ],
                null,
                'store_order',
                (int) $order->id,
                true
            );
        });
    }

    public function notifyPasswordResetOtp(\App\Models\User $user, string $code): AdminNotification
    {
        return $this->withArabicLocale(function () use ($user, $code) {
            $name = (string) ($user->name ?: $user->email);
            $email = (string) $user->email;

            return $this->create(
                self::TYPE_PASSWORD_RESET_OTP,
                __('messages.admin_notify_password_reset_otp_title'),
                __('messages.admin_notify_password_reset_otp_body', [
                    'user' => $name,
                    'email' => $email,
                    'code' => $code,
                ]),
                [
                    'user_id' => (string) $user->id,
                    'user_name' => $name,
                    'email' => $email,
                    'otp' => $code,
                    'requested_at' => now()->toIso8601String(),
                ],
                null,
                'password_reset_code',
                null,
                true
            );
        });
    }

    public function notifyStockImagesExportReady(StockImageExport $export): AdminNotification
    {
        return $this->withArabicLocale(function () use ($export) {
            $downloadUrl = url('/api/products/images-zip-exports/'.$export->id.'/download');

            return $this->create(
                self::TYPE_STOCK_IMAGES_EXPORT_READY,
                'ملف صور المخزون جاهز',
                'الملف تبع صور المخزون جاهز، روح حمّله من شاشة المخزون.',
                [
                    'export_id' => (string) $export->id,
                    'download_url' => $downloadUrl,
                    'file_name' => (string) ($export->file_name ?? ''),
                    'file_size' => (string) ($export->file_size ?? 0),
                    'file_size_human' => $this->humanFileSize((int) ($export->file_size ?? 0)),
                    'images_added' => (string) ($export->images_added ?? 0),
                    'completed_at' => optional($export->completed_at)->toIso8601String() ?? now()->toIso8601String(),
                ],
                null,
                'stock_image_export',
                (int) $export->id,
                true
            );
        });
    }

    public function notifyTaskCompleted(EmployeeDetail $employee, EmployeeTask $task): AdminNotification
    {
        $employee->loadMissing('user');
        $name = $employee->user->name ?? 'Employee';
        $taskTitle = $task->name ?? 'Task';
        $completedAt = now()->toIso8601String();

        $data = [
            'employee_id' => (string) $employee->id,
            'employee_name' => $name,
            'task_id' => (string) $task->id,
            'task_title' => $taskTitle,
            'completed_at' => $completedAt,
        ];

        return $this->create(
            self::TYPE_EMPLOYEE_TASK_COMPLETED,
            'Task Completed',
            "{$name} completed task: {$taskTitle}.",
            $data,
            $employee->id,
            'employee_task',
            $task->id,
            true
        );
    }

    /**
     * Employee finished a task and submitted it for admin review (legacy row).
     */
    public function notifyTaskSubmittedForReview(EmployeeDetail $employee, EmployeeTask $task): AdminNotification
    {
        return $this->notifyTaskSubmitted(
            $employee,
            $task->name ?? 'Task',
            (int) $task->id,
            null
        );
    }

    /**
     * Employee finished an occurrence (v2) and submitted for review.
     */
    public function notifyOccurrenceSubmittedForReview(
        EmployeeDetail $employee,
        EmployeeTaskOccurrence $occurrence
    ): AdminNotification {
        return $this->notifyTaskSubmitted(
            $employee,
            $occurrence->name ?? 'Task',
            (int) ($occurrence->legacy_task_id ?? $occurrence->id),
            (int) $occurrence->id
        );
    }

    public function notifyTaskSubmitted(
        EmployeeDetail $employee,
        string $taskTitle,
        int $taskId,
        ?int $occurrenceId = null
    ): AdminNotification {
        $employee->loadMissing('user');
        $name = $employee->user->name ?? 'Employee';
        $submittedAt = now()->toIso8601String();

        $data = [
            'employee_id' => (string) $employee->id,
            'employee_name' => $name,
            'task_id' => (string) $taskId,
            'task_title' => $taskTitle,
            'occurrence_id' => $occurrenceId !== null ? (string) $occurrenceId : '',
            'status' => 'waiting_review',
            'submitted_at' => $submittedAt,
        ];

        return $this->create(
            self::TYPE_EMPLOYEE_TASK_SUBMITTED,
            __('messages.admin_notify_task_submitted_title'),
            __('messages.admin_notify_task_submitted_body', [
                'employee' => $name,
                'task' => $taskTitle,
            ]),
            $data,
            $employee->id,
            $occurrenceId !== null ? 'employee_task_occurrence' : 'employee_task',
            $occurrenceId ?? $taskId,
            true
        );
    }

    public function notifyLegacySubtaskCompleted(
        EmployeeDetail $employee,
        EmployeeSubTask $subTask
    ): AdminNotification {
        $subTask->loadMissing('employeeTask');
        $task = $subTask->employeeTask;
        $taskTitle = $task?->name ?? 'Task';
        $taskId = (int) ($task?->id ?? 0);
        $subTitle = $subTask->name ?? 'Subtask';
        $progress = $this->legacySubtaskProgress($task);

        return $this->notifySubtaskCompleted(
            $employee,
            $subTitle,
            $taskTitle,
            $taskId,
            null,
            (int) $subTask->id,
            $progress
        );
    }

    public function notifyOccurrenceSubtaskCompleted(
        EmployeeDetail $employee,
        EmployeeTaskOccurrenceSubtask $subTask
    ): AdminNotification {
        $subTask->loadMissing('occurrence');
        $occurrence = $subTask->occurrence;
        $taskTitle = $occurrence?->name ?? 'Task';
        $taskId = (int) ($occurrence?->legacy_task_id ?? $occurrence?->id ?? 0);
        $occurrenceId = $occurrence ? (int) $occurrence->id : null;
        $subTitle = $subTask->name ?? 'Subtask';
        $progress = $occurrence ? $this->occurrenceSubtaskProgress($occurrence) : null;

        return $this->notifySubtaskCompleted(
            $employee,
            $subTitle,
            $taskTitle,
            $taskId,
            $occurrenceId,
            (int) $subTask->id,
            $progress
        );
    }

    /**
     * @param  array{done: int, total: int}|null  $progress
     */
    public function notifySubtaskCompleted(
        EmployeeDetail $employee,
        string $subtaskTitle,
        string $taskTitle,
        int $taskId,
        ?int $occurrenceId,
        int $subtaskId,
        ?array $progress = null
    ): AdminNotification {
        $employee->loadMissing('user');
        $name = $employee->user->name ?? 'Employee';
        $completedAt = now()->toIso8601String();

        $data = [
            'employee_id' => (string) $employee->id,
            'employee_name' => $name,
            'task_id' => (string) $taskId,
            'task_title' => $taskTitle,
            'occurrence_id' => $occurrenceId !== null ? (string) $occurrenceId : '',
            'sub_task_id' => (string) $subtaskId,
            'sub_task_title' => $subtaskTitle,
            'completed_at' => $completedAt,
        ];

        if ($progress !== null) {
            $data['subtasks_done'] = (string) $progress['done'];
            $data['subtasks_total'] = (string) $progress['total'];
        }

        $bodyParams = [
            'employee' => $name,
            'subtask' => $subtaskTitle,
            'task' => $taskTitle,
        ];

        if ($progress !== null && $progress['total'] > 0) {
            $bodyParams['done'] = (string) $progress['done'];
            $bodyParams['total'] = (string) $progress['total'];
            $body = __('messages.admin_notify_subtask_completed_body_progress', $bodyParams);
        } else {
            $body = __('messages.admin_notify_subtask_completed_body', $bodyParams);
        }

        return $this->create(
            self::TYPE_EMPLOYEE_SUBTASK_COMPLETED,
            __('messages.admin_notify_subtask_completed_title'),
            $body,
            $data,
            $employee->id,
            $occurrenceId !== null ? 'employee_task_occurrence_subtask' : 'sub_employee_task',
            $subtaskId,
            true
        );
    }

    /**
     * @return array{done: int, total: int}|null
     */
    private function legacySubtaskProgress(?EmployeeTask $task): ?array
    {
        if (! $task) {
            return null;
        }

        $total = (int) $task->subTasks()->count();
        if ($total === 0) {
            return null;
        }

        return [
            'done' => (int) $task->subTasks()->where('status', 'completed')->count(),
            'total' => $total,
        ];
    }

    /**
     * @return array{done: int, total: int}|null
     */
    private function occurrenceSubtaskProgress(EmployeeTaskOccurrence $occurrence): ?array
    {
        $total = (int) $occurrence->subtasks()->count();
        if ($total === 0) {
            return null;
        }

        return [
            'done' => (int) $occurrence->subtasks()->where('status', 'completed')->count(),
            'total' => $total,
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, EmployeeTask>  $pendingTasks
     */
    public function notifyEmployeeLogoutWithPendingTasks(
        EmployeeDetail $employee,
        ?int $attendanceId,
        $pendingTasks,
        ?string $logoutTime = null
    ): ?AdminNotification {
        if ($pendingTasks->isEmpty()) {
            return null;
        }

        if ($this->hasLogoutPendingNotificationToday($employee->id)) {
            return null;
        }

        $employee->loadMissing('user');
        $name = $employee->user->name ?? __('messages.employee_default_name');
        $count = $pendingTasks->count();
        $time = $this->formatNotificationTime($logoutTime);

        $pendingList = $pendingTasks->map(fn (EmployeeTask $t) => [
            'id' => $t->id,
            'title' => $t->name,
            'status' => $t->status,
        ])->values()->all();

        $data = [
            'employee_id' => (string) $employee->id,
            'employee_name' => $name,
            'attendance_id' => $attendanceId !== null ? (string) $attendanceId : '',
            'pending_tasks_count' => (string) $count,
            'pending_tasks' => $pendingList,
            'logout_time' => $time,
        ];

        return $this->create(
            self::TYPE_EMPLOYEE_LOGOUT_PENDING_TASKS,
            __('messages.admin_notify_logout_pending_title'),
            __('messages.admin_notify_logout_pending_body', [
                'employee' => $name,
                'count' => (string) $count,
            ]),
            $data,
            $employee->id,
            $attendanceId !== null ? 'employee_attendance' : null,
            $attendanceId,
            true
        );
    }

    protected function formatNotificationTime(?string $isoOrDatetime = null): string
    {
        $tz = EmployeePendingTasksForToday::TIMEZONE;

        if ($isoOrDatetime !== null && trim($isoOrDatetime) !== '') {
            try {
                $at = Carbon::parse($isoOrDatetime)->timezone($tz);
            } catch (\Throwable) {
                $at = now($tz);
            }
        } else {
            $at = now($tz);
        }

        $date = $at->format('Y-m-d');
        $time = $at->locale('ar')->translatedFormat('g:i a');

        return "{$date} {$time}";
    }

    protected function attendanceSourceLabel(string $source): string
    {
        return match ($source) {
            'fingerprint' => __('messages.admin_notify_source_fingerprint'),
            'qr' => __('messages.admin_notify_source_qr'),
            'manual' => __('messages.admin_notify_source_manual'),
            'auto' => __('messages.admin_notify_source_auto'),
            default => '',
        };
    }

    private function humanFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $size = (float) $bytes;
        $unit = 0;

        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return number_format($size, $unit === 0 ? 0 : 1).' '.$units[$unit];
    }

    public function hasAbsentReminderForDate(string $workDate): bool
    {
        return AdminNotification::query()
            ->where('type', self::TYPE_ATTENDANCE_ABSENT_REMINDER)
            ->where('data->work_date', $workDate)
            ->exists();
    }

    /**
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    protected function withArabicLocale(callable $callback): mixed
    {
        $previous = App::getLocale();
        App::setLocale('ar');

        try {
            return $callback();
        } finally {
            App::setLocale($previous);
        }
    }

    public function notifyCheckDueSoon(IncomingCheck|OutgoingCheck $check, string $direction, string $reminderDate): ?AdminNotification
    {
        $relatedType = $check instanceof IncomingCheck ? 'incoming_check' : 'outgoing_check';
        $checkNumber = (string) ($check->check_id ?? $check->id);
        $dueDate = $check->due_date ? Carbon::parse($check->due_date)->toDateString() : '';
        $amount = (string) ($check->total ?? '');
        $bank = trim((string) ($check->bank_name ?? ''));
        $notes = trim((string) ($check->notes ?? ''));

        if ($this->checkDueReminderExists($relatedType, (int) $check->id, $reminderDate)) {
            return null;
        }

        $dirLabel = $direction === 'incoming' ? 'وارد' : 'صادر';

        $bodyParts = ["شيك {$dirLabel} رقم {$checkNumber}"];
        if ($bank !== '') {
            $bodyParts[] = "البنك: {$bank}";
        }
        $bodyParts[] = "تاريخ الاستحقاق: {$dueDate}";
        if ($notes !== '') {
            $bodyParts[] = "ملاحظة: {$notes}";
        }
        $body = implode(' - ', $bodyParts);

        $data = [
            'check_id' => (string) $check->id,
            'check_number' => $checkNumber,
            'check_type' => $direction,
            'amount' => $amount,
            'due_date' => $dueDate,
            'bank_name' => $bank,
            'notes' => $notes,
            'reminder_date' => $reminderDate,
        ];

        return $this->create(
            self::TYPE_CHECK_DUE_REMINDER,
            'تذكير باستحقاق شيك',
            $body,
            $data,
            null,
            $relatedType,
            (int) $check->id,
            true
        );
    }

    public function checkDueReminderExists(string $relatedType, int $checkId, string $reminderDate): bool
    {
        return AdminNotification::query()
            ->where('type', self::TYPE_CHECK_DUE_REMINDER)
            ->where('related_type', $relatedType)
            ->where('related_id', $checkId)
            ->where('data->reminder_date', $reminderDate)
            ->exists();
    }

    protected function hasLogoutPendingNotificationToday(int $employeeId): bool
    {
        return AdminNotification::query()
            ->where('type', self::TYPE_EMPLOYEE_LOGOUT_PENDING_TASKS)
            ->where('employee_id', $employeeId)
            ->whereDate('created_at', now()->toDateString())
            ->exists();
    }

    /**
     * @return array{sent: int, failed: int, token_count: int}
     */
    public function pushToAdminDevices(AdminNotification $notification): array
    {
        $tokensQuery = AdminDeviceToken::query();
        if ($notification->recipient_user_id !== null) {
            $tokensQuery->where('user_id', $notification->recipient_user_id);
        }

        $tokens = $tokensQuery->pluck('fcm_token')->all();
        $tokenCount = count($tokens);

        Log::info('Admin FCM broadcast start', [
            'notification_id' => $notification->id,
            'type' => $notification->type,
            'title' => $notification->title,
            'recipient_user_id' => $notification->recipient_user_id,
            'token_count' => $tokenCount,
            'channel_id' => FirebaseService::ADMIN_CHANNEL_ID,
        ]);

        if ($tokens === []) {
            Log::warning('Admin FCM broadcast skipped: no device tokens');

            return ['sent' => 0, 'failed' => 0, 'token_count' => 0];
        }

        $data = $this->buildFcmDataPayload($notification);
        $sent = 0;
        $failed = 0;

        foreach ($tokens as $token) {
            try {
                $response = $this->firebaseService->sendToTokenQuietly(
                    $token,
                    $notification->title,
                    $notification->body,
                    $data
                );
                if ($response !== null) {
                    $sent++;
                } else {
                    $failed++;
                }
            } catch (\Throwable $e) {
                $failed++;
                Log::error('Admin FCM broadcast token failure', [
                    'notification_id' => $notification->id,
                    'token_prefix' => substr($token, 0, 12).'…',
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('Admin FCM broadcast finished', [
            'notification_id' => $notification->id,
            'sent' => $sent,
            'failed' => $failed,
            'token_count' => $tokenCount,
        ]);

        return [
            'sent' => $sent,
            'failed' => $failed,
            'token_count' => $tokenCount,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function buildFcmDataPayload(AdminNotification $notification): array
    {
        $row = $notification->fresh();
        $merged = array_merge($row->data ?? [], [
            'notification_id' => (string) $row->id,
            'type' => (string) $row->type,
            'related_type' => (string) ($row->related_type ?? ''),
            'related_id' => (string) ($row->related_id ?? ''),
            'employee_id' => (string) ($row->employee_id ?? ''),
            'recipient_user_id' => (string) ($row->recipient_user_id ?? ''),
            'task_id' => '',
            'check_id' => '',
        ]);

        if ($row->type === self::TYPE_EMPLOYEE_TASK_COMPLETED
            || $row->type === self::TYPE_EMPLOYEE_TASK_SUBMITTED
            || $row->type === self::TYPE_EMPLOYEE_SUBTASK_COMPLETED) {
            $merged['task_id'] = (string) (
                $merged['task_id']
                ?? $merged['occurrence_id']
                ?? $row->related_id
                ?? ''
            );
        }

        if ($row->type === self::TYPE_CHECK_DUE_REMINDER
            || $row->type === self::TYPE_CHECK_CASHED
            || $row->type === self::TYPE_CHECK_RETURNED) {
            $merged['check_id'] = (string) ($merged['check_id'] ?? $row->related_id ?? '');
        }

        return $this->stringifyData($merged);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    protected function stringifyData(array $data): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            if (is_array($v) || is_object($v)) {
                $out[(string) $k] = json_encode($v, JSON_UNESCAPED_UNICODE);
            } elseif ($v === null) {
                $out[(string) $k] = '';
            } else {
                $out[(string) $k] = (string) $v;
            }
        }

        return $out;
    }
}
