<?php

namespace App\Console\Commands;

use App\Models\Note;
use App\Services\NoteNotificationService;
use Illuminate\Console\Command;

class NotesSendDueReminders extends Command
{
    protected $signature = 'notes:send-due-reminders';

    protected $description = 'Send push notifications for due note reminders.';

    public function handle(NoteNotificationService $notifications): int
    {
        $processed = 0;
        $notified = 0;

        Note::query()
            ->with([
                'owner.employee.user',
                'collaborators.user.employee.user',
            ])
            ->where('is_archived', false)
            ->whereNotNull('reminder_at')
            ->whereNull('reminder_notified_at')
            ->where('reminder_at', '<=', now())
            ->orderBy('id')
            ->chunkById(100, function ($notes) use (&$processed, &$notified, $notifications) {
                foreach ($notes as $note) {
                    $processed++;
                    $notified += $notifications->notifyReminderDue($note);
                    $note->forceFill(['reminder_notified_at' => now()])->save();
                }
            });

        $this->info("Processed {$processed} due note reminders. Notifications created: {$notified}.");

        return self::SUCCESS;
    }
}
