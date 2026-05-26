<?php

namespace App\Services;

use App\Models\EmployeeDetail;
use App\Models\EmployeeReminder;
use App\Models\EmployeeReminderOccurrence;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EmployeeReminderService
{
    public function __construct(
        private readonly EmployeeNotificationService $notifications
    ) {}

    /**
     * @param  list<int>  $employeeIds
     * @return Collection<int, EmployeeReminder>
     */
    public function createForEmployees(array $employeeIds, array $data, ?int $createdBy = null): Collection
    {
        return DB::transaction(function () use ($employeeIds, $data, $createdBy) {
            $scheduledAt = Carbon::parse($data['scheduled_at']);
            $reminders = collect();

            foreach (array_unique($employeeIds) as $employeeId) {
                $reminder = EmployeeReminder::create([
                    'employee_id' => $employeeId,
                    'created_by' => $createdBy,
                    'title' => $data['title'],
                    'description' => $data['description'] ?? null,
                    'scheduled_at' => $scheduledAt,
                    'repeat_type' => $data['repeat_type'] ?? EmployeeReminder::REPEAT_ONCE,
                    'is_active' => (bool) ($data['is_active'] ?? true),
                ]);

                $this->ensureOccurrence($reminder, $scheduledAt);
                $reminders->push($reminder->fresh(['employee.user', 'occurrences']));
            }

            return $reminders;
        });
    }

    public function updateReminder(EmployeeReminder $reminder, array $data): EmployeeReminder
    {
        return DB::transaction(function () use ($reminder, $data) {
            $reminder->fill($data);
            $reminder->save();

            if (array_key_exists('scheduled_at', $data)) {
                $scheduledAt = Carbon::parse($data['scheduled_at']);
                $pending = $reminder->occurrences()
                    ->whereIn('status', [EmployeeReminderOccurrence::STATUS_PENDING, EmployeeReminderOccurrence::STATUS_SNOOZED])
                    ->orderBy('scheduled_at')
                    ->first();

                if ($pending) {
                    $pending->update([
                        'scheduled_at' => $scheduledAt,
                        'notified_at' => null,
                        'snoozed_until' => null,
                        'status' => EmployeeReminderOccurrence::STATUS_PENDING,
                    ]);
                } else {
                    $this->ensureOccurrence($reminder, $scheduledAt);
                }
            }

            return $reminder->fresh(['employee.user', 'occurrences']);
        });
    }

    public function markDone(EmployeeReminderOccurrence $occurrence): EmployeeReminderOccurrence
    {
        return DB::transaction(function () use ($occurrence) {
            $occurrence->loadMissing('reminder');
            $occurrence->update([
                'status' => EmployeeReminderOccurrence::STATUS_DONE,
                'completed_at' => now(),
                'snoozed_until' => null,
            ]);

            $this->createNextOccurrenceIfNeeded($occurrence->reminder, Carbon::parse($occurrence->scheduled_at));

            return $occurrence->fresh(['reminder']);
        });
    }

    public function snooze(EmployeeReminderOccurrence $occurrence, int $minutes = 30): EmployeeReminderOccurrence
    {
        $minutes = max(5, min($minutes, 1440));
        $occurrence->update([
            'status' => EmployeeReminderOccurrence::STATUS_SNOOZED,
            'snoozed_until' => now()->addMinutes($minutes),
            'notified_at' => null,
        ]);

        return $occurrence->fresh(['reminder']);
    }

    public function sendDueNotifications(): array
    {
        $now = now();
        $stats = ['due' => 0, 'sent' => 0, 'failed' => 0];

        EmployeeReminderOccurrence::query()
            ->with(['employee.user', 'reminder'])
            ->whereIn('status', [EmployeeReminderOccurrence::STATUS_PENDING, EmployeeReminderOccurrence::STATUS_SNOOZED])
            ->where(function ($query) use ($now) {
                $query->where('scheduled_at', '<=', $now)
                    ->orWhere('snoozed_until', '<=', $now);
            })
            ->whereNull('notified_at')
            ->whereHas('reminder', fn ($query) => $query->where('is_active', true))
            ->orderBy('scheduled_at')
            ->chunkById(100, function ($occurrences) use (&$stats) {
                foreach ($occurrences as $occurrence) {
                    $stats['due']++;

                    try {
                        $this->notifyOccurrence($occurrence);
                        $occurrence->update([
                            'notified_at' => now(),
                            'status' => EmployeeReminderOccurrence::STATUS_PENDING,
                            'snoozed_until' => null,
                        ]);
                        $stats['sent']++;
                    } catch (\Throwable $e) {
                        $stats['failed']++;
                        Log::error('employee_reminder.notification_failed', [
                            'occurrence_id' => $occurrence->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        return $stats;
    }

    private function notifyOccurrence(EmployeeReminderOccurrence $occurrence): void
    {
        $reminder = $occurrence->reminder;
        $body = trim((string) $reminder->description);
        if ($body === '') {
            $body = 'لديك تذكير عمل جديد يحتاج انتباهك.';
        }

        $this->notifications->create(
            $occurrence->employee,
            EmployeeNotificationService::TYPE_OPERATIONAL_REMINDER,
            'تذكير عمل: '.$reminder->title,
            $body,
            [
                'reminder_id' => (string) $reminder->id,
                'occurrence_id' => (string) $occurrence->id,
                'scheduled_at' => optional($occurrence->scheduled_at)->toIso8601String(),
            ],
            'employee_reminder_occurrence',
            (int) $occurrence->id
        );
    }

    private function ensureOccurrence(EmployeeReminder $reminder, Carbon $scheduledAt): EmployeeReminderOccurrence
    {
        return EmployeeReminderOccurrence::firstOrCreate([
            'reminder_id' => $reminder->id,
            'scheduled_at' => $scheduledAt,
        ], [
            'employee_id' => $reminder->employee_id,
            'status' => EmployeeReminderOccurrence::STATUS_PENDING,
        ]);
    }

    private function createNextOccurrenceIfNeeded(EmployeeReminder $reminder, Carbon $currentScheduledAt): void
    {
        if (! $reminder->is_active || $reminder->repeat_type === EmployeeReminder::REPEAT_ONCE) {
            return;
        }

        $next = match ($reminder->repeat_type) {
            EmployeeReminder::REPEAT_DAILY => $currentScheduledAt->copy()->addDay(),
            EmployeeReminder::REPEAT_WEEKLY => $currentScheduledAt->copy()->addWeek(),
            EmployeeReminder::REPEAT_MONTHLY => $currentScheduledAt->copy()->addMonth(),
            default => null,
        };

        if ($next !== null) {
            $this->ensureOccurrence($reminder, $next);
        }
    }
}
