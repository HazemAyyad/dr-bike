<?php

namespace App\Services\Support;

use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\SupportMessageAttachment;
use App\Support\ProfileImageUrl;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

final class SupportPayloadService
{
    public function __construct(private readonly SupportPresenceService $presence) {}

    public function conversation(SupportConversation $conversation): array
    {
        $conversation->loadMissing([
            'employee.user:id,name',
            'requester:id,name,email,phone,profile_image_path,last_seen_at',
            'assignee:id,name',
            'suggestion:id,title,category,is_anonymous',
        ]);
        $snapshot = is_array($conversation->context_snapshot) ? $conversation->context_snapshot : null;

        return [
            'id' => (int) $conversation->getKey(),
            'source' => (string) ($conversation->source ?: SupportConversation::SOURCE_EMPLOYEE),
            'employee_id' => $conversation->employee_id === null ? null : (int) $conversation->employee_id,
            'employee_name' => (string) ($conversation->employee?->user?->name ?? ''),
            'requester_user_id' => $conversation->requester_user_id === null ? null : (int) $conversation->requester_user_id,
            'requester_name' => (string) ($conversation->requester?->name ?? $conversation->employee?->user?->name ?? ''),
            'requester_email' => (string) ($conversation->requester?->email ?? ''),
            'requester_phone' => (string) ($conversation->requester?->phone ?? ''),
            'requester_image_url' => ProfileImageUrl::resolve($conversation->requester?->profile_image_path),
            'requester_last_seen_at' => optional($conversation->requester?->last_seen_at)->toIso8601String(),
            'requester_is_online' => $conversation->requester?->last_seen_at?->gte(now()->subMinutes(2)) ?? false,
            ...$this->presence->snapshot((int) $conversation->getKey()),
            'context_type' => (string) ($conversation->context_type ?: SupportConversation::CONTEXT_GENERAL),
            'online_store_listing_id' => $conversation->online_store_listing_id === null
                ? null
                : (int) $conversation->online_store_listing_id,
            'product_context' => $snapshot,
            'subject' => $conversation->subject,
            'status' => (string) $conversation->status,
            'priority' => (string) $conversation->priority,
            'last_message' => $conversation->last_message,
            'last_message_at' => optional($conversation->last_message_at)->toIso8601String(),
            'employee_unread_count' => (int) $conversation->employee_unread_count,
            'requester_unread_count' => (int) $conversation->requester_unread_count,
            'support_unread_count' => (int) $conversation->support_unread_count,
            'messages_count' => (int) ($conversation->messages_count ?? 0),
            'assigned_to_user_id' => $conversation->assigned_to_user_id === null
                ? null
                : (int) $conversation->assigned_to_user_id,
            'assigned_to_name' => (string) ($conversation->assignee?->name ?? ''),
            'employee_suggestion_id' => $conversation->employee_suggestion_id,
            'suggestion_title' => $conversation->suggestion?->title,
            'suggestion_category' => $conversation->suggestion?->category,
            'first_support_response_at' => optional($conversation->first_support_response_at)->toIso8601String(),
            'last_requester_message_at' => optional($conversation->last_requester_message_at)->toIso8601String(),
            'last_support_message_at' => optional($conversation->last_support_message_at)->toIso8601String(),
            'created_at' => optional($conversation->created_at)->toIso8601String(),
            'closed_at' => optional($conversation->closed_at)->toIso8601String(),
        ];
    }

    public function message(SupportMessage $message, ?int $viewerUserId = null): array
    {
        $message->loadMissing(['attachments', 'senderUser:id,name,profile_image_path', 'reactions.user:id,name']);
        $reactions = $message->reactions;

        return [
            'id' => (int) $message->getKey(),
            'conversation_id' => (int) $message->support_conversation_id,
            'client_message_id' => $message->client_message_id,
            'sender_user_id' => $message->sender_user_id === null ? null : (int) $message->sender_user_id,
            'sender_employee_id' => $message->sender_employee_id === null ? null : (int) $message->sender_employee_id,
            'sender_name' => (string) ($message->senderUser?->name ?? ''),
            'sender_image_url' => ProfileImageUrl::resolve($message->senderUser?->profile_image_path),
            'sender_type' => (string) $message->sender_type,
            'message_type' => (string) $message->message_type,
            'body' => $message->body,
            'attachments' => $message->attachments->map(fn (SupportMessageAttachment $attachment) => [
                'id' => (int) $attachment->getKey(),
                'type' => (string) $attachment->attachment_type,
                'url' => $this->attachmentUrl($attachment),
                'original_name' => $attachment->original_name,
                'mime_type' => $attachment->mime_type,
                'size' => (int) $attachment->size,
            ])->values(),
            'reactions' => $reactions->groupBy('reaction')->map(fn ($items, $reaction) => [
                'reaction' => (string) $reaction,
                'count' => $items->count(),
                'reacted' => $viewerUserId !== null
                    && $items->contains(fn ($item) => (int) $item->user_id === $viewerUserId),
                'users' => $items->map(fn ($item) => (string) ($item->user?->name ?? ''))->filter()->values(),
            ])->values(),
            'my_reaction' => $viewerUserId === null
                ? null
                : optional($reactions->first(fn ($item) => (int) $item->user_id === $viewerUserId))->reaction,
            'created_at' => optional($message->created_at)->toIso8601String(),
        ];
    }

    private function attachmentUrl(SupportMessageAttachment $attachment): string
    {
        if ($attachment->disk !== 'local') {
            return (string) ($attachment->url ?: Storage::disk($attachment->disk)->url($attachment->path));
        }

        return URL::temporarySignedRoute(
            'support.attachments.download',
            now()->addMinutes(15),
            ['attachment' => $attachment->getKey()]
        );
    }
}
