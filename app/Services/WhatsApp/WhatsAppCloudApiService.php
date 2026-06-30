<?php

namespace App\Services\WhatsApp;

use App\Models\Customer;
use App\Models\Seller;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class WhatsAppCloudApiService
{
    public function validateConfig(): void
    {
        $missing = collect([
            'WHATSAPP_API_VERSION' => config('whatsapp.api_version'),
            'WHATSAPP_ACCESS_TOKEN' => config('whatsapp.access_token'),
            'WHATSAPP_PHONE_NUMBER_ID' => config('whatsapp.phone_number_id'),
        ])->filter(fn ($value) => blank($value))->keys();

        if ($missing->isNotEmpty()) {
            throw new RuntimeException('WhatsApp configuration is missing: '.$missing->join(', '));
        }
    }

    public function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/[^\d+]/', '', trim($phone));
        $phone = ltrim($phone, '+');
        if (str_starts_with($phone, '00')) {
            $phone = substr($phone, 2);
        }
        if (! preg_match('/^[1-9]\d{7,14}$/', $phone)) {
            throw new RuntimeException('Invalid phone number. Use international format including country code.');
        }
        return $phone;
    }

    public function sendText(string $phone, string $message, ?int $adminId = null): array
    {
        return $this->send($phone, [
            'type' => 'text',
            'text' => ['preview_url' => false, 'body' => $message],
        ], ['message_type' => 'text', 'body' => $message], $adminId);
    }

    public function sendTemplate(string $phone, string $templateName, string $language = 'ar', array $components = [], ?int $adminId = null): array
    {
        return $this->send($phone, [
            'type' => 'template',
            'template' => array_filter([
                'name' => $templateName,
                'language' => ['code' => $language],
                'components' => $components ?: null,
            ]),
        ], [
            'message_type' => 'template',
            'template_name' => $templateName,
            'body' => $templateName,
        ], $adminId);
    }

    public function markAsRead(string $messageId): array
    {
        $this->validateConfig();
        $response = $this->client()->post($this->endpoint(), [
            'messaging_product' => 'whatsapp',
            'status' => 'read',
            'message_id' => $messageId,
        ]);
        return $this->responseArray($response);
    }

    public function findOrCreateContact(string $phone, ?string $name = null): WhatsAppContact
    {
        $phone = $this->normalizePhone($phone);
        $contact = WhatsAppContact::query()->firstOrNew(['phone' => $phone]);

        if (! $contact->exists) {
            $candidate = Customer::query()->where('phone', 'like', '%'.substr($phone, -8))->first();
            $seller = $candidate ? null : Seller::query()->where('phone', 'like', '%'.substr($phone, -8))->first();
            $employee = ($candidate || $seller) ? null : User::query()->where('phone', 'like', '%'.substr($phone, -8))->first();
            $contact->fill([
                'name' => $name ?: $candidate?->name ?: $seller?->name ?: $employee?->name,
                'customer_id' => $candidate?->id,
                'supplier_id' => $seller?->id,
                'employee_id' => $employee?->id,
            ]);
        } elseif ($name && ! $contact->name) {
            $contact->name = $name;
        }
        $contact->save();
        return $contact;
    }

    public function findOrCreateConversation(string $phone): WhatsAppConversation
    {
        $contact = $this->findOrCreateContact($phone);
        return WhatsAppConversation::query()->firstOrCreate(
            ['whatsapp_contact_id' => $contact->id, 'status' => 'open'],
            ['phone' => $contact->phone]
        );
    }

    private function send(string $phone, array $payload, array $messageData, ?int $adminId): array
    {
        $this->validateConfig();
        $phone = $this->normalizePhone($phone);
        $contact = $this->findOrCreateContact($phone);
        $conversation = $this->findOrCreateConversation($phone);
        $message = WhatsAppMessage::query()->create(array_merge($messageData, [
            'whatsapp_conversation_id' => $conversation->id,
            'whatsapp_contact_id' => $contact->id,
            'phone' => $phone,
            'direction' => 'outbound',
            'status' => 'pending',
            'sent_by' => $adminId,
        ]));

        try {
            $response = $this->client()->post($this->endpoint(), array_merge([
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $phone,
            ], $payload));
            $data = $this->responseArray($response);
            $metaId = data_get($data, 'body.messages.0.id');
            $message->update([
                'meta_message_id' => $metaId,
                'meta_status' => $response->successful() ? 'accepted' : 'failed',
                'status' => $response->successful() ? 'sent' : 'failed',
                'response_payload' => $data['body'],
                'error_message' => $response->successful() ? null : (data_get($data, 'body.error.message') ?: 'Meta API request failed'),
            ]);
            if ($response->successful()) {
                $conversation->update(['last_message' => $message->body, 'last_message_at' => now()]);
                $contact->update(['last_message_at' => now()]);
            }
            return ['message' => $message->fresh(), 'api_response' => $data];
        } catch (\Throwable $e) {
            $message->update(['status' => 'failed', 'meta_status' => 'failed', 'error_message' => $e->getMessage()]);
            throw $e;
        }
    }

    private function client()
    {
        return Http::withToken(config('whatsapp.access_token'))
            ->acceptJson()
            ->asJson()
            ->timeout(config('whatsapp.timeout', 20));
    }

    private function endpoint(): string
    {
        return sprintf(
            'https://graph.facebook.com/%s/%s/messages',
            trim(config('whatsapp.api_version'), '/'),
            config('whatsapp.phone_number_id')
        );
    }

    private function responseArray(Response $response): array
    {
        return ['successful' => $response->successful(), 'status_code' => $response->status(), 'body' => $response->json() ?: []];
    }
}
