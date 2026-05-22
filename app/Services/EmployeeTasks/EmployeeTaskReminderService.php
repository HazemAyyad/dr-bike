<?php

namespace App\Services\EmployeeTasks;

use App\Enums\EmployeeTaskStatus;
use App\Mail\EmployeeTaskReminderMail;
use App\Models\EmployeeDetail;
use App\Models\EmployeeTask;
use App\Models\EmployeeTaskOccurrence;
use App\Services\EmployeeNotificationService;
use App\Support\EmployeePendingTasksForToday;
use App\Support\EmployeeVisibleTasks;
use App\Support\TaskReminderConfig;
use Carbon\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

class EmployeeTaskReminderService
{
    private const TZ = EmployeePendingTasksForToday::TIMEZONE;

    private const WINDOW_MINUTES = 10;

    public function __construct(
        private readonly EmployeeNotificationService $employeeNotifications
    ) {}

    /**
     * @return array{occurrences: int, legacy: int, sent: int, skipped: int}
     */
    public function dispatchDueReminders(): array
    {
        $stats = ['occurrences' => 0, 'legacy' => 0, 'sent' => 0, 'skipped' => 0];
        $now = now()->timezone(self::TZ);

        if (Schema::hasTable('employee_task_occurrences')) {
            $occurrences = EmployeeTaskOccurrence::query()
                ->with(['template', 'employee.user'])
                ->where('is_canceled', 0)
                ->whereIn('status', $this->activeStatuses())
                ->whereNotNull('start_time')
                ->get();

            foreach ($occurrences as $occurrence) {
                $stats['occurrences']++;
                if ($this->processOccurrence($occurrence, $now)) {
                    $stats['sent']++;
                } else {
                    $stats['skipped']++;
                }
            }
        }

        $legacyQuery = EmployeeTask::query()
            ->with('employee.user')
            ->where('is_canceled', 0)
            ->whereIn('status', $this->activeStatuses())
            ->whereNotNull('start_time');

        if (Schema::hasColumn('employee_tasks', 'reminder_before_minutes')) {
            $legacyQuery->whereNotNull('reminder_before_minutes');
        }

        foreach ($legacyQuery->get() as $task) {
            $stats['legacy']++;
            if ($this->processLegacyTask($task, $now)) {
                $stats['sent']++;
            } else {
                $stats['skipped']++;
            }
        }

        return $stats;
    }

    private function processOccurrence(EmployeeTaskOccurrence $occurrence, Carbon $now): bool
    {
        $template = $occurrence->template;
        if (! $template) {
            return false;
        }

        if (! EmployeeVisibleTasks::isOccurrenceVisibleToEmployee($occurrence)) {
            return false;
        }

        $reminder = TaskReminderConfig::fromRecurrenceConfig($template->recurrence_config);
        if ($reminder === null) {
            return false;
        }

        return $this->sendIfDue(
            $occurrence->employee,
            $occurrence->name ?? $template->name,
            Carbon::parse($occurrence->start_time)->timezone(self::TZ),
            $reminder['minutes'],
            $reminder['channel'],
            'occ',
            (int) $occurrence->id,
            $now
        );
    }

    private function processLegacyTask(EmployeeTask $task, Carbon $now): bool
    {
        if (! EmployeePendingTasksForToday::isVisibleToEmployee($task)) {
            return false;
        }

        $reminder = TaskReminderConfig::fromLegacyTask(
            Schema::hasColumn('employee_tasks', 'reminder_before_minutes')
                ? $task->reminder_before_minutes
                : null,
            Schema::hasColumn('employee_tasks', 'reminder_channel')
                ? $task->reminder_channel
                : null
        );

        if ($reminder === null) {
            $reminder = $task->template
                ? TaskReminderConfig::fromRecurrenceConfig($task->template->recurrence_config ?? [])
                : null;
        }

        if ($reminder === null) {
            return false;
        }

        $employee = $task->employee;
        if (! $employee) {
            return false;
        }

        return $this->sendIfDue(
            $employee,
            $task->name,
            Carbon::parse($task->start_time)->timezone(self::TZ),
            $reminder['minutes'],
            $reminder['channel'],
            'legacy',
            (int) $task->id,
            $now
        );
    }

    private function sendIfDue(
        ?EmployeeDetail $employee,
        string $taskName,
        Carbon $start,
        int $minutesBefore,
        string $channel,
        string $kind,
        int $id,
        Carbon $now
    ): bool {
        if (! $employee) {
            return false;
        }

        $remindAt = TaskReminderConfig::remindAt($start, $minutesBefore);
        if (! TaskReminderConfig::isDueNow($remindAt, $now, self::WINDOW_MINUTES)) {
            return false;
        }

        $cacheKey = sprintf(
            'task_reminder_sent:%s:%d:%s',
            $kind,
            $id,
            $remindAt->format('Y-m-d-H-i')
        );

        if (Cache::has($cacheKey)) {
            return false;
        }

        try {
            if ($channel === TaskReminderConfig::CHANNEL_EMAIL) {
                $previous = App::getLocale();
                App::setLocale('ar');
                try {
                    $body = $this->bodyForReminder($minutesBefore, $start, $taskName);
                    $this->sendEmail($employee, $taskName, $body);
                } finally {
                    App::setLocale($previous);
                }
            } else {
                $this->employeeNotifications->notifyTaskScheduledReminder(
                    $employee,
                    $taskName,
                    $minutesBefore,
                    $start,
                    $kind,
                    $id
                );
            }

            Cache::put($cacheKey, true, $start->copy()->addDay());

            return true;
        } catch (\Throwable $e) {
            Log::error('task_reminder.send_failed', [
                'kind' => $kind,
                'id' => $id,
                'employee_id' => $employee->id,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function bodyForReminder(int $minutesBefore, Carbon $start, string $taskName): string
    {
        if ($minutesBefore <= 0) {
            return __('messages.employee_task_reminder_body_at_time', [
                'name' => $taskName,
                'time' => $start->format('Y-m-d H:i'),
            ]);
        }

        return __('messages.employee_task_reminder_body_before', [
            'name' => $taskName,
            'time' => $start->format('Y-m-d H:i'),
        ]);
    }

    private function sendEmail(EmployeeDetail $employee, string $taskName, string $body): void
    {
        $employee->loadMissing('user');
        $email = trim((string) ($employee->user->email ?? ''));
        if ($email === '') {
            throw new \RuntimeException('Employee has no email');
        }

        Mail::to($email)->send(new EmployeeTaskReminderMail(
            $employee->user->name ?? '',
            $taskName,
            $body
        ));
    }

    /**
     * @return list<string>
     */
    private function activeStatuses(): array
    {
        return [
            EmployeeTaskStatus::Pending->value,
            EmployeeTaskStatus::InProgress->value,
            EmployeeTaskStatus::Ongoing->value,
            EmployeeTaskStatus::Overdue->value,
        ];
    }
}
