<?php

namespace App\Services\Meta;

use App\Models\EmployeeDetail;
use App\Models\SocialMessage;
use App\Services\AdminNotificationService;
use App\Services\EmployeeNotificationService;
use Illuminate\Support\Facades\Log;

class SocialIncomingNotificationService
{
    public const TYPE = 'social_message_received';
    public const PERMISSION = 'Messages Section';

    public function __construct(
        protected AdminNotificationService $adminNotifications,
        protected EmployeeNotificationService $employeeNotifications
    ) {}

    public function notify(SocialMessage $message): void
    {
        $message->loadMissing(['contact', 'conversation']);
        $sender = $message->contact?->name ?: $message->contact?->external_id ?: 'زبون';
        $channelLabel = $this->channelLabel($message->channel);
        $preview = $message->message_type === 'text'
            ? mb_strimwidth((string) $message->body, 0, 120, '...')
            : $this->mediaLabel($message->message_type);
        $title = 'رسالة '.$channelLabel.' جديدة';
        $body = $sender.': '.$preview;
        $data = [
            'channel' => (string) $message->channel,
            'conversation_id' => (string) $message->social_conversation_id,
            'message_id' => (string) $message->id,
            'sender_id' => (string) $message->external_sender_id,
            'sender_name' => (string) $sender,
        ];

        try {
            $this->adminNotifications->create(
                self::TYPE,
                $title,
                $body,
                $data,
                null,
                'social_conversation',
                $message->social_conversation_id,
                true
            );
        } catch (\Throwable $e) {
            Log::warning('Social admin notification failed', ['error' => $e->getMessage()]);
        }

        EmployeeDetail::query()
            ->with('user')
            ->whereHas('permissions.permission', fn ($query) => $query->where('name_en', self::PERMISSION))
            ->whereHas('user')
            ->each(function (EmployeeDetail $employee) use ($title, $body, $data, $message) {
                try {
                    $this->employeeNotifications->create(
                        $employee,
                        self::TYPE,
                        $title,
                        $body,
                        $data,
                        'social_conversation',
                        $message->social_conversation_id,
                        true
                    );
                } catch (\Throwable $e) {
                    Log::warning('Social employee notification failed', [
                        'employee_id' => $employee->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            });
    }

    private function channelLabel(string $channel): string
    {
        return match ($channel) {
            'facebook' => 'فيسبوك',
            'instagram' => 'إنستغرام',
            default => 'تواصل',
        };
    }

    private function mediaLabel(string $type): string
    {
        return match ($type) {
            'image' => 'أرسل صورة',
            'document' => 'أرسل ملفا',
            'audio' => 'أرسل رسالة صوتية',
            'video' => 'أرسل فيديو',
            default => 'أرسل رسالة جديدة',
        };
    }
}
