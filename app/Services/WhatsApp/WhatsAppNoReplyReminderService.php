<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use Illuminate\Support\Facades\DB;

class WhatsAppNoReplyReminderService
{
    public function sendDue(WhatsAppCloudApiService $api): array
    {
        $stats = ['eligible' => 0, 'sent' => 0, 'failed' => 0];
        $windowStart = now()->subHours(24);
        $reminderDueAt = now()->subHours(23);

        WhatsAppConversation::query()
            ->with('whatsappAccount')
            ->whereIn('status', ['open', 'pending'])
            ->whereHas('whatsappAccount', fn ($query) => $query->where('is_active', true))
            ->whereHas('messages', fn ($query) => $query
                ->where('direction', 'inbound')
                ->whereBetween('created_at', [$windowStart, $reminderDueAt]))
            ->orderBy('id')
            ->chunkById(100, function ($conversations) use ($api, &$stats, $windowStart, $reminderDueAt) {
                foreach ($conversations as $conversation) {
                    $inbound = $conversation->messages()
                        ->where('direction', 'inbound')
                        ->whereBetween('created_at', [$windowStart, $reminderDueAt])
                        ->latest('created_at')
                        ->latest('id')
                        ->first();
                    if (! $inbound || $this->hasEmployeeReply($conversation, $inbound)) {
                        continue;
                    }
                    if (DB::table('whatsapp_no_reply_reminders')
                        ->where('inbound_message_id', $inbound->id)
                        ->where('status', 'sent')
                        ->exists()) {
                        continue;
                    }

                    $stats['eligible']++;
                    DB::table('whatsapp_no_reply_reminders')->updateOrInsert(
                        ['inbound_message_id' => $inbound->id],
                        [
                            'whatsapp_conversation_id' => $conversation->id,
                            'status' => 'pending',
                            'error_message' => null,
                            'updated_at' => now(),
                            'created_at' => now(),
                        ]
                    );
                    try {
                        $api->forAccount($conversation->whatsappAccount)->sendReplyButtons(
                            $conversation->phone,
                            "أهلًا بك 👋\nلاحظنا أننا لم نتمكن من الرد عليك بعد. هل ما زلت بحاجة إلى المساعدة؟",
                            [
                                ['id' => 'flow:no_reply:yes', 'title' => 'نعم، أريد المتابعة'],
                                ['id' => 'flow:no_reply:no', 'title' => 'لا، شكرًا'],
                            ]
                        );
                        DB::table('whatsapp_no_reply_reminders')
                            ->where('inbound_message_id', $inbound->id)
                            ->update(['status' => 'sent', 'sent_at' => now(), 'updated_at' => now()]);
                        $stats['sent']++;
                    } catch (\Throwable $e) {
                        DB::table('whatsapp_no_reply_reminders')
                            ->where('inbound_message_id', $inbound->id)
                            ->update(['status' => 'failed', 'error_message' => $e->getMessage(), 'updated_at' => now()]);
                        $stats['failed']++;
                    }
                }
            });

        return $stats;
    }

    private function hasEmployeeReply(WhatsAppConversation $conversation, WhatsAppMessage $inbound): bool
    {
        return $conversation->messages()
            ->where('direction', 'outbound')
            ->where('is_automatic', false)
            ->where('created_at', '>', $inbound->created_at)
            ->exists();
    }
}
