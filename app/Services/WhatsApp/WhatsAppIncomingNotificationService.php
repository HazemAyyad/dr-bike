<?php

namespace App\Services\WhatsApp;

use App\Models\EmployeeDetail;
use App\Models\WhatsAppMessage;
use App\Services\AdminNotificationService;
use App\Services\EmployeeNotificationService;
use Illuminate\Support\Facades\Log;

class WhatsAppIncomingNotificationService
{
    public const TYPE = 'whatsapp_message_received';
    public const PERMISSION = 'Social Center WhatsApp';

    public function __construct(
        protected AdminNotificationService $adminNotifications,
        protected EmployeeNotificationService $employeeNotifications
    ) {}

    public function notify(WhatsAppMessage $message): void
    {
        $message->loadMissing(['contact', 'conversation']);
        $sender = $message->contact?->name ?: $message->phone;
        $preview = $message->message_type === 'text'
            ? mb_strimwidth((string) $message->body, 0, 120, '…')
            : $this->mediaLabel($message->message_type);
        $title = 'رسالة واتساب جديدة';
        $body = $sender.': '.$preview;
        $data = [
            'conversation_id' => (string) $message->whatsapp_conversation_id,
            'message_id' => (string) $message->id,
            'phone' => (string) $message->phone,
            'sender_name' => (string) $sender,
        ];

        try {
            $this->adminNotifications->create(
                self::TYPE,
                $title,
                $body,
                $data,
                null,
                'whatsapp_conversation',
                $message->whatsapp_conversation_id,
                true
            );
        } catch (\Throwable $e) {
            Log::warning('WhatsApp admin notification failed', ['error' => $e->getMessage()]);
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
                        'whatsapp_conversation',
                        $message->whatsapp_conversation_id,
                        true
                    );
                } catch (\Throwable $e) {
                    Log::warning('WhatsApp employee notification failed', [
                        'employee_id' => $employee->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            });
    }

    private function mediaLabel(string $type): string
    {
        return match ($type) {
            'image' => 'أرسل صورة',
            'document' => 'أرسل ملفاً',
            'audio' => 'أرسل رسالة صوتية',
            'video' => 'أرسل فيديو',
            'location' => 'أرسل موقعاً',
            default => 'أرسل رسالة جديدة',
        };
    }
}
