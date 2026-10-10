<?php

namespace App\Services\Support;

use App\Models\EmployeeDetail;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\User;
use App\Services\AdminNotificationService;
use App\Services\EmployeeNotificationService;
use App\Support\ProfileImageUrl;

final class StoreSupportNotificationService
{
    public function __construct(
        private readonly AdminNotificationService $adminNotifications,
        private readonly EmployeeNotificationService $employeeNotifications,
    ) {}

    public function afterMessage(SupportConversation $conversation, SupportMessage $message): void
    {
        if ($conversation->source !== SupportConversation::SOURCE_ONLINE_STORE) {
            return;
        }

        $data = [
            'conversation_id' => (string) $conversation->getKey(),
            'support_conversation_id' => (string) $conversation->getKey(),
            'message_id' => (string) $message->getKey(),
            'destination_type' => 'support_conversation',
            'destination_id' => (string) $conversation->getKey(),
            'source' => 'online_store_support',
        ];

        if ($message->sender_type === SupportMessage::SENDER_SUPPORT) {
            $recipient = User::query()->find($conversation->requester_user_id);
            if (! $recipient || (int) $recipient->getKey() === (int) $message->sender_user_id) {
                return;
            }
            $this->adminNotifications->createStoreRecipientNotification(
                $recipient,
                AdminNotificationService::TYPE_STORE_SUPPORT_MESSAGE,
                'رد جديد من دعم المتجر',
                $this->preview($message),
                $data,
                'support_conversation',
                (int) $conversation->getKey(),
            );

            return;
        }

        $conversation->loadMissing('requester:id,name,profile_image_path');
        $requesterName = (string) ($conversation->requester?->name ?? 'عميل المتجر');
        $data['customer_image_url'] = ProfileImageUrl::resolve(
            $conversation->requester?->profile_image_path
        ) ?? '';
        $body = $requesterName.': '.$this->preview($message);
        $this->adminNotifications->create(
            AdminNotificationService::TYPE_SUPPORT_MESSAGE,
            'رسالة جديدة من عميل المتجر',
            $body,
            $data,
            null,
            'support_conversation',
            (int) $conversation->getKey(),
            true,
        );

        EmployeeDetail::query()
            ->with('user:id,name,fcm_token')
            ->whereHas('permissions.permission', fn ($query) => $query->where(
                'name_en',
                SupportAccessService::STORE_PERMISSION
            ))
            ->get()
            ->each(function (EmployeeDetail $employee) use ($body, $data, $message, $conversation) {
                if ((int) $employee->id === (int) $message->sender_employee_id) {
                    return;
                }
                $this->employeeNotifications->create(
                    $employee,
                    EmployeeNotificationService::TYPE_SUPPORT_MESSAGE,
                    'رسالة جديدة من عميل المتجر',
                    $body,
                    $data,
                    'support_conversation',
                    (int) $conversation->getKey(),
                    true,
                );
            });
    }

    private function preview(SupportMessage $message): string
    {
        return mb_substr((string) ($message->body ?: match ($message->message_type) {
            SupportMessage::TYPE_IMAGE => 'صورة',
            SupportMessage::TYPE_VIDEO => 'فيديو',
            SupportMessage::TYPE_AUDIO => 'رسالة صوتية',
            default => 'مرفق',
        }), 0, 120);
    }
}
