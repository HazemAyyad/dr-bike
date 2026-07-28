<?php

namespace App\Services\Meta;

use App\Models\SocialContact;
use App\Models\SocialConversation;
use App\Models\SocialMessage;
use Carbon\Carbon;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class MetaMessagingService
{
    public function validateConfig(): void
    {
        $missing = collect([
            'META_PAGE_ACCESS_TOKEN' => config('meta_messaging.page_access_token'),
            'FACEBOOK_PAGE_ID' => config('meta_messaging.page_id'),
        ])->filter(fn ($value) => blank($value))->keys();

        if ($missing->isNotEmpty()) {
            throw new RuntimeException('Meta messaging configuration is missing: '.$missing->join(', '));
        }
    }

    public function sendText(SocialConversation $conversation, string $message, ?int $adminId = null): array
    {
        $this->validateConfig();

        $payload = [
            'recipient' => ['id' => $conversation->contact->external_id],
            'message' => ['text' => $message],
            'messaging_type' => 'RESPONSE',
        ];

        $localMessage = SocialMessage::query()->create([
            'social_conversation_id' => $conversation->id,
            'social_contact_id' => $conversation->social_contact_id,
            'channel' => $conversation->channel,
            'external_sender_id' => $this->senderId($conversation->channel),
            'external_recipient_id' => $conversation->contact->external_id,
            'direction' => 'outbound',
            'message_type' => 'text',
            'body' => $message,
            'status' => 'pending',
            'sent_by' => $adminId,
        ]);

        try {
            $response = $this->client()->post($this->endpoint('me/messages'), $payload);
            $data = $this->responseArray($response);
            $localMessage->update([
                'meta_message_id' => data_get($data, 'body.message_id'),
                'meta_status' => $response->successful() ? 'accepted' : 'failed',
                'status' => $response->successful() ? 'sent' : 'failed',
                'response_payload' => $data['body'],
                'error_message' => $response->successful() ? null : (data_get($data, 'body.error.message') ?: 'Meta API request failed'),
            ]);

            if ($response->successful()) {
                $conversation->update([
                    'last_message' => $message,
                    'last_message_at' => now(),
                ]);
                $conversation->contact->update(['last_message_at' => now()]);
            }

            return ['message' => $localMessage->fresh(), 'api_response' => $data];
        } catch (\Throwable $e) {
            $localMessage->update([
                'status' => 'failed',
                'meta_status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function findOrCreateContact(string $channel, string $externalId, ?string $name = null, array $profile = []): SocialContact
    {
        $contact = SocialContact::query()->firstOrNew([
            'channel' => $channel,
            'external_id' => $externalId,
        ]);

        if (! $contact->exists || ($name && ! $contact->name)) {
            $contact->name = $name ?: $contact->name;
        }

        if ($profile !== []) {
            $contact->profile_picture_url = data_get($profile, 'profile_pic') ?: $contact->profile_picture_url;
            $contact->raw_profile = array_merge($contact->raw_profile ?: [], $profile);
        }

        $contact->save();
        return $contact;
    }

    public function findOrCreateConversation(SocialContact $contact): SocialConversation
    {
        return SocialConversation::query()->firstOrCreate(
            [
                'social_contact_id' => $contact->id,
                'status' => 'open',
            ],
            ['channel' => $contact->channel]
        );
    }

    public function readProfile(string $channel, string $externalId): array
    {
        try {
            $fields = $channel === 'instagram'
                ? 'name,username,profile_pic'
                : 'first_name,last_name,profile_pic';
            $response = $this->client()->get($this->endpoint($externalId), ['fields' => $fields]);
            if (! $response->successful()) {
                Log::warning('Meta messaging profile lookup failed', [
                    'channel' => $channel,
                    'external_id' => $externalId,
                    'status' => $response->status(),
                    'error' => data_get($response->json() ?: [], 'error.message'),
                ]);
                return [];
            }
            $data = $response->json() ?: [];
            if ($channel === 'facebook') {
                $data['name'] = trim((string) data_get($data, 'first_name').' '.(string) data_get($data, 'last_name'));
            }
            return $data;
        } catch (\Throwable $e) {
            Log::warning('Meta messaging profile lookup exception', [
                'channel' => $channel,
                'external_id' => $externalId,
                'message' => $e->getMessage(),
            ]);
            return [];
        }
    }

    public function saveIncoming(string $channel, array $event): ?SocialMessage
    {
        $message = data_get($event, 'message');
        if (! is_array($message)) {
            return null;
        }

        $metaId = data_get($message, 'mid');
        if (! $metaId || SocialMessage::query()->where('meta_message_id', $metaId)->exists()) {
            return null;
        }

        $senderId = (string) data_get($event, 'sender.id');
        $recipientId = (string) data_get($event, 'recipient.id');
        if ($senderId === '') {
            return null;
        }

        $profile = $this->readProfile($channel, $senderId);
        $contact = $this->findOrCreateContact($channel, $senderId, data_get($profile, 'name') ?: data_get($profile, 'username'), $profile);
        $conversation = $this->findOrCreateConversation($contact);
        $type = $this->messageType($message);
        $body = $this->messageBody($message, $type);
        $timestamp = data_get($event, 'timestamp')
            ? Carbon::createFromTimestampMs((int) data_get($event, 'timestamp'))
            : now();

        $saved = SocialMessage::query()->create([
            'social_conversation_id' => $conversation->id,
            'social_contact_id' => $contact->id,
            'channel' => $channel,
            'external_sender_id' => $senderId,
            'external_recipient_id' => $recipientId,
            'direction' => 'inbound',
            'message_type' => $type,
            'body' => $body,
            'media_url' => data_get($message, 'attachments.0.payload.url'),
            'meta_message_id' => $metaId,
            'meta_status' => 'received',
            'raw_payload' => $event,
            'status' => 'received',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $conversation->update([
            'last_message' => $body,
            'last_message_at' => $timestamp,
            'unread_count' => $conversation->unread_count + 1,
        ]);
        $contact->update(['last_message_at' => $timestamp]);

        return $saved;
    }

    private function messageType(array $message): string
    {
        $attachmentType = (string) data_get($message, 'attachments.0.type');
        return match ($attachmentType) {
            'image' => 'image',
            'audio' => 'audio',
            'video' => 'video',
            'file' => 'document',
            'fallback' => 'system',
            default => filled(data_get($message, 'text')) ? 'text' : ($attachmentType ?: 'system'),
        };
    }

    private function messageBody(array $message, string $type): string
    {
        if (filled(data_get($message, 'text'))) {
            return (string) data_get($message, 'text');
        }

        return match ($type) {
            'image' => '[image]',
            'audio' => '[audio]',
            'video' => '[video]',
            'document' => '[file]',
            default => '[message]',
        };
    }

    private function senderId(string $channel): string
    {
        return $channel === 'instagram'
            ? (string) config('meta_messaging.instagram_business_account_id')
            : (string) config('meta_messaging.page_id');
    }

    private function client()
    {
        return Http::withToken(config('meta_messaging.page_access_token'))
            ->acceptJson()
            ->asJson()
            ->timeout(config('meta_messaging.timeout', 20));
    }

    private function endpoint(string $path): string
    {
        return sprintf(
            'https://graph.facebook.com/%s/%s',
            trim(config('meta_messaging.api_version'), '/'),
            ltrim($path, '/')
        );
    }

    private function responseArray(Response $response): array
    {
        return [
            'successful' => $response->successful(),
            'status_code' => $response->status(),
            'body' => $response->json() ?: [],
        ];
    }
}
