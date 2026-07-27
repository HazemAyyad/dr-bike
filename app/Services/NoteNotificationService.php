<?php

namespace App\Services;

use App\Models\Note;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class NoteNotificationService
{
    public function __construct(
        protected AdminNotificationService $adminNotifications,
        protected EmployeeNotificationService $employeeNotifications
    ) {}

    public function notifyCollaboratorAdded(Note $note, int $recipientUserId, int $actorUserId): bool
    {
        $user = User::query()
            ->with('employee.user')
            ->whereKey($recipientUserId)
            ->first();

        if (! $user) {
            return false;
        }

        $note->loadMissing('owner:id,name,type');
        $title = 'تمت مشاركة ملاحظة معك';
        $noteTitle = trim((string) ($note->title ?: 'ملاحظة بدون عنوان'));
        $body = "تمت إضافتك كمعاون على {$noteTitle}";

        return $this->notifyUser(
            $user,
            AdminNotificationService::TYPE_NOTE_SHARED,
            EmployeeNotificationService::TYPE_NOTE_SHARED,
            $title,
            $body,
            $note,
            [
                'event' => 'note_shared',
                'actor_user_id' => (string) $actorUserId,
            ]
        );
    }

    public function notifyReminderDue(Note $note): int
    {
        $note->loadMissing([
            'owner.employee.user',
            'collaborators.user.employee.user',
        ]);

        $users = collect([$note->owner])
            ->merge($note->collaborators->pluck('user'))
            ->filter()
            ->unique('id')
            ->values();

        $sent = 0;
        $noteTitle = trim((string) ($note->title ?: 'ملاحظة بدون عنوان'));
        $title = 'تذكير ملاحظة';
        $body = "حان وقت التذكير: {$noteTitle}";

        foreach ($users as $user) {
            if ($this->notifyUser(
                $user,
                AdminNotificationService::TYPE_NOTE_REMINDER,
                EmployeeNotificationService::TYPE_NOTE_REMINDER,
                $title,
                $body,
                $note,
                [
                    'event' => 'note_reminder',
                    'reminder_at' => optional($note->reminder_at)->toIso8601String(),
                ]
            )) {
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function notifyUser(
        User $user,
        string $adminType,
        string $employeeType,
        string $title,
        string $body,
        Note $note,
        array $data = []
    ): bool {
        $user->loadMissing('employee.user');

        $payload = array_merge([
            'note_id' => (string) $note->id,
            'note_title' => (string) ($note->title ?? ''),
            'owner_user_id' => (string) $note->owner_user_id,
        ], $data);

        try {
            if ($user->type === 'employee') {
                $employee = $user->employee;
                if (! $employee) {
                    Log::warning('Note notification skipped: employee profile missing', [
                        'note_id' => $note->id,
                        'user_id' => $user->id,
                    ]);

                    return false;
                }

                $this->employeeNotifications->create(
                    $employee,
                    $employeeType,
                    $title,
                    $body,
                    $payload,
                    'note',
                    (int) $note->id,
                    true
                );

                return true;
            }

            $this->adminNotifications->create(
                $adminType,
                $title,
                $body,
                $payload,
                $user->employee?->id,
                'note',
                (int) $note->id,
                true,
                (int) $user->id
            );

            return true;
        } catch (\Throwable $e) {
            Log::error('Note notification failed', [
                'note_id' => $note->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
