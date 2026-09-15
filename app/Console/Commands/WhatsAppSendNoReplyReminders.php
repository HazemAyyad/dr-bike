<?php

namespace App\Console\Commands;

use App\Services\WhatsApp\WhatsAppCloudApiService;
use App\Services\WhatsApp\WhatsAppNoReplyReminderService;
use Illuminate\Console\Command;

class WhatsAppSendNoReplyReminders extends Command
{
    protected $signature = 'whatsapp:send-no-reply-reminders';

    protected $description = 'Prompt WhatsApp customers shortly before an unanswered service window expires';

    public function handle(
        WhatsAppNoReplyReminderService $reminders,
        WhatsAppCloudApiService $api
    ): int {
        $stats = $reminders->sendDue($api);
        $this->info(sprintf(
            'WhatsApp no-reply reminders: eligible=%d sent=%d failed=%d',
            $stats['eligible'],
            $stats['sent'],
            $stats['failed']
        ));

        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
