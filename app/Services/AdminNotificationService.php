<?php

namespace App\Services;

use App\Models\AdminDeviceToken;
use App\Models\AdminNotification;
use App\Models\EmployeeDetail;
use App\Models\EmployeeTask;
use App\Models\IncomingCheck;
use App\Models\OutgoingCheck;
use App\Support\EmployeePendingTasksForToday;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class AdminNotificationService
{
    public function __construct(
        protected FirebaseService $firebaseService
    ) {}

    public const TYPE_EMPLOYEE_LOGIN = 'employee_login';

    public const TYPE_EMPLOYEE_TASK_COMPLETED = 'employee_task_completed';

    public const TYPE_EMPLOYEE_LOGOUT_PENDING_TASKS = 'employee_logout_pending_tasks';

    public const TYPE_CHECK_DUE_REMINDER = 'check_due_reminder';

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
        bool $sendPush = true
    ): AdminNotification {
        $notification = AdminNotification::create([
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'employee_id' => $employeeId,
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

    public function notifyEmployeeLogin(EmployeeDetail $employee, ?int $attendanceId = null): AdminNotification
    {
        $employee->loadMissing('user');
        $name = $employee->user->name ?? 'Employee';
        $time = now()->format('Y-m-d H:i:s');

        $data = [
            'employee_id' => (string) $employee->id,
            'employee_name' => $name,
            'login_time' => $time,
            'attendance_id' => $attendanceId !== null ? (string) $attendanceId : '',
        ];

        return $this->create(
            self::TYPE_EMPLOYEE_LOGIN,
            'Employee Logged In',
            "{$name} logged in at {$time}.",
            $data,
            $employee->id,
            $attendanceId !== null ? 'employee_attendance' : null,
            $attendanceId,
            true
        );
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
     * @param  \Illuminate\Support\Collection<int, EmployeeTask>  $pendingTasks
     */
    public function notifyEmployeeLogoutWithPendingTasks(
        EmployeeDetail $employee,
        ?int $attendanceId,
        $pendingTasks,
        string $logoutTime
    ): ?AdminNotification {
        if ($pendingTasks->isEmpty()) {
            return null;
        }

        if ($this->hasLogoutPendingNotificationToday($employee->id)) {
            return null;
        }

        $employee->loadMissing('user');
        $name = $employee->user->name ?? 'Employee';
        $count = $pendingTasks->count();

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
            'logout_time' => $logoutTime,
        ];

        return $this->create(
            self::TYPE_EMPLOYEE_LOGOUT_PENDING_TASKS,
            'Employee Logged Out With Pending Tasks',
            "{$name} logged out without completing {$count} assigned tasks.",
            $data,
            $employee->id,
            $attendanceId !== null ? 'employee_attendance' : null,
            $attendanceId,
            true
        );
    }

    public function notifyCheckDueSoon(IncomingCheck|OutgoingCheck $check, string $direction, string $reminderDate): ?AdminNotification
    {
        $relatedType = $check instanceof IncomingCheck ? 'incoming_check' : 'outgoing_check';
        $checkNumber = (string) ($check->check_id ?? $check->id);
        $dueDate = $check->due_date ? Carbon::parse($check->due_date)->toDateString() : '';
        $amount = (string) ($check->total ?? '');

        if ($this->checkDueReminderExists($relatedType, (int) $check->id, $reminderDate)) {
            return null;
        }

        $dirLabel = $direction === 'incoming' ? 'Incoming' : 'Outgoing';

        $data = [
            'check_id' => (string) $check->id,
            'check_number' => $checkNumber,
            'check_type' => $direction,
            'amount' => $amount,
            'due_date' => $dueDate,
            'reminder_date' => $reminderDate,
        ];

        return $this->create(
            self::TYPE_CHECK_DUE_REMINDER,
            'Check Due Soon',
            "{$dirLabel} check #{$checkNumber} is due on {$dueDate}.",
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

    public function pushToAdminDevices(AdminNotification $notification): void
    {
        $tokens = AdminDeviceToken::query()->pluck('fcm_token')->all();
        $tokenCount = count($tokens);

        Log::info('Admin FCM broadcast start', [
            'notification_id' => $notification->id,
            'type' => $notification->type,
            'title' => $notification->title,
            'token_count' => $tokenCount,
            'channel_id' => FirebaseService::ADMIN_CHANNEL_ID,
        ]);

        if ($tokens === []) {
            Log::warning('Admin FCM broadcast skipped: no device tokens');

            return;
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
            'task_id' => '',
            'check_id' => '',
        ]);

        if ($row->type === self::TYPE_EMPLOYEE_TASK_COMPLETED) {
            $merged['task_id'] = (string) ($merged['task_id'] ?? $row->related_id ?? '');
        }

        if ($row->type === self::TYPE_CHECK_DUE_REMINDER) {
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
