<?php

namespace App\Services\Support;

use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SupportMessageManager
{
    /**
     * @param  list<UploadedFile>  $attachments
     */
    public function create(
        SupportConversation $conversation,
        Authenticatable $actor,
        string $senderType,
        ?string $body,
        array $attachments = [],
        ?string $clientMessageId = null,
    ): SupportMessage {
        if ($clientMessageId) {
            $existing = SupportMessage::query()
                ->where('support_conversation_id', $conversation->getKey())
                ->where('client_message_id', $clientMessageId)
                ->first();
            if ($existing) {
                if ((int) $existing->sender_user_id !== (int) $actor->getAuthIdentifier()
                    || trim((string) $existing->body) !== trim((string) $body)) {
                    throw ValidationException::withMessages([
                        'client_message_id' => ['The message identity is already used for different content.'],
                    ]);
                }

                return $existing->load(['attachments', 'senderUser:id,name', 'reactions.user:id,name']);
            }
        }

        $employeeId = $actor instanceof User ? $actor->employee?->id : null;
        $message = SupportMessage::query()->create([
            'support_conversation_id' => $conversation->getKey(),
            'client_message_id' => $clientMessageId,
            'sender_user_id' => $actor->getAuthIdentifier(),
            'sender_employee_id' => $employeeId,
            'sender_type' => $senderType,
            'message_type' => $this->messageType($attachments),
            'body' => filled($body) ? trim((string) $body) : null,
        ]);

        foreach ($attachments as $attachment) {
            if ($attachment instanceof UploadedFile) {
                $this->storeAttachment($conversation, $message, $attachment);
            }
        }

        $this->touchConversation($conversation, $message, $senderType);

        return $message->fresh(['attachments', 'senderUser:id,name', 'reactions.user:id,name']);
    }

    private function storeAttachment(
        SupportConversation $conversation,
        SupportMessage $message,
        UploadedFile $file,
    ): void {
        $private = $conversation->source === SupportConversation::SOURCE_ONLINE_STORE;
        $disk = $private ? 'local' : 'public';
        $directory = $private
            ? 'support/private/'.$conversation->getKey()
            : 'support/attachments/'.$conversation->getKey();
        $extension = strtolower((string) ($file->getClientOriginalExtension() ?: $file->extension()));
        $filename = Str::uuid()->toString().($extension === '' ? '' : '.'.$extension);
        $path = $file->storeAs($directory, $filename, $disk);

        $message->attachments()->create([
            'disk' => $disk,
            'path' => $path,
            'url' => $private ? null : Storage::disk($disk)->url($path),
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => (int) $file->getSize(),
            'attachment_type' => $this->attachmentType($file),
        ]);
    }

    private function touchConversation(
        SupportConversation $conversation,
        SupportMessage $message,
        string $senderType,
    ): void {
        $supportSender = $senderType === SupportMessage::SENDER_SUPPORT;
        $now = now();
        $updates = [
            'last_message' => mb_substr(
                (string) ($message->body ?: $this->attachmentLabel($message->message_type)),
                0,
                500
            ),
            'last_message_at' => $now,
        ];

        if ($supportSender) {
            $updates['requester_unread_count'] = DB::raw('requester_unread_count + 1');
            if ($conversation->source === SupportConversation::SOURCE_EMPLOYEE) {
                $updates['employee_unread_count'] = DB::raw('employee_unread_count + 1');
            }
            $updates['support_unread_count'] = 0;
            $updates['last_support_message_at'] = $now;
            if (! $conversation->first_support_response_at) {
                $updates['first_support_response_at'] = $now;
            }
        } else {
            $updates['support_unread_count'] = DB::raw('support_unread_count + 1');
            $updates['requester_unread_count'] = 0;
            if ($conversation->source === SupportConversation::SOURCE_EMPLOYEE) {
                $updates['employee_unread_count'] = 0;
            }
            $updates['last_requester_message_at'] = $now;
        }

        $conversation->update($updates);
    }

    /** @param list<UploadedFile> $attachments */
    private function messageType(array $attachments): string
    {
        $first = $attachments[0] ?? null;

        return $first instanceof UploadedFile ? $this->attachmentType($first) : SupportMessage::TYPE_TEXT;
    }

    private function attachmentType(UploadedFile $file): string
    {
        $mime = (string) $file->getMimeType();
        $extension = strtolower((string) ($file->getClientOriginalExtension() ?: $file->extension()));

        return match (true) {
            in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'heic', 'heif'], true) => SupportMessage::TYPE_IMAGE,
            in_array($extension, ['mp3', 'm4a', 'aac', 'ogg', 'wav'], true) => SupportMessage::TYPE_AUDIO,
            in_array($extension, ['mp4', 'mov', 'webm', '3gp', 'm4v', 'avi'], true) => SupportMessage::TYPE_VIDEO,
            str_starts_with($mime, 'image/') => SupportMessage::TYPE_IMAGE,
            str_starts_with($mime, 'audio/') => SupportMessage::TYPE_AUDIO,
            str_starts_with($mime, 'video/') => SupportMessage::TYPE_VIDEO,
            default => SupportMessage::TYPE_DOCUMENT,
        };
    }

    private function attachmentLabel(string $type): string
    {
        return match ($type) {
            SupportMessage::TYPE_IMAGE => 'أرسل صورة',
            SupportMessage::TYPE_VIDEO => 'أرسل فيديو',
            SupportMessage::TYPE_AUDIO => 'أرسل تسجيل صوتي',
            SupportMessage::TYPE_DOCUMENT => 'أرسل ملف',
            default => 'رسالة دعم',
        };
    }
}
