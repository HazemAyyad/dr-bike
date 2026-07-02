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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
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

    public function sendText(
        string $phone,
        string $message,
        ?int $adminId = null,
        ?WhatsAppMessage $replyTo = null,
        bool $automatic = false
    ): array
    {
        return $this->send($phone, [
            'context' => $replyTo?->meta_message_id ? ['message_id' => $replyTo->meta_message_id] : null,
            'type' => 'text',
            'text' => ['preview_url' => false, 'body' => $message],
        ], [
            'message_type' => 'text',
            'body' => $message,
            'reply_to_message_id' => $replyTo?->id,
            'reply_to_meta_message_id' => $replyTo?->meta_message_id,
            'is_automatic' => $automatic,
        ], $adminId);
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

    public function sendMedia(string $phone, UploadedFile $file, ?string $caption = null, ?int $adminId = null): array
    {
        $this->validateConfig();
        $type = $this->mediaType($file->getMimeType());
        $upload = Http::withToken(config('whatsapp.access_token'))
            ->acceptJson()
            ->timeout(config('whatsapp.timeout', 20))
            ->attach('file', fopen($file->getRealPath(), 'r'), $file->getClientOriginalName(), [
                'Content-Type' => $file->getMimeType(),
            ])
            ->post($this->graphEndpoint(config('whatsapp.phone_number_id').'/media'), [
                'messaging_product' => 'whatsapp',
            ]);

        if (! $upload->successful() || ! $upload->json('id')) {
            throw new RuntimeException($upload->json('error.message') ?: 'WhatsApp media upload failed.');
        }

        $mediaId = (string) $upload->json('id');
        $media = array_filter([
            'id' => $mediaId,
            'caption' => in_array($type, ['image', 'video', 'document'], true) ? $caption : null,
            'filename' => $type === 'document' ? $file->getClientOriginalName() : null,
        ]);

        return $this->send($phone, [
            'type' => $type,
            $type => $media,
        ], [
            'message_type' => $type,
            'body' => $type === 'document'
                ? ($caption ?: $file->getClientOriginalName())
                : $caption,
            'media_url' => $mediaId,
        ], $adminId);
    }

    public function sendProductList(string $phone, array $products, ?int $adminId = null): array
    {
        $catalogId = (string) config('meta_commerce.catalog_id');
        if ($catalogId === '') {
            throw new RuntimeException('META_CATALOG_ID is not configured.');
        }

        $rows = collect($products)
            ->map(fn ($product) => ['product_retailer_id' => $product->meta_catalog_retailer_id])
            ->values()
            ->all();

        return $this->send($phone, [
            'type' => 'interactive',
            'interactive' => [
                'type' => 'product_list',
                'header' => ['type' => 'text', 'text' => 'منتجات دكتور بايك'],
                'body' => ['text' => 'المنتجات التي اخترناها لك'],
                'footer' => ['text' => 'اضغط على المنتج لعرض التفاصيل'],
                'action' => [
                    'catalog_id' => $catalogId,
                    'sections' => [[
                        'title' => 'المنتجات',
                        'product_items' => $rows,
                    ]],
                ],
            ],
        ], [
            'message_type' => 'interactive',
            'body' => 'مشاركة '.count($rows).' منتجات',
        ], $adminId);
    }

    public function sendWelcomeMenu(string $phone): array
    {
        return $this->send($phone, [
            'type' => 'interactive',
            'interactive' => [
                'type' => 'list',
                'header' => [
                    'type' => 'text',
                    'text' => 'كيف يمكننا مساعدتك؟',
                ],
                'body' => [
                    'text' => 'اختر القسم المناسب حتى نوجّه طلبك بسرعة.',
                ],
                'footer' => [
                    'text' => 'د. بايك لخدمات الدراجات',
                ],
                'action' => [
                    'button' => 'اختر الخدمة',
                    'sections' => [[
                        'title' => 'الخدمات',
                        'rows' => [
                            [
                                'id' => 'products',
                                'title' => 'المنتجات',
                                'description' => 'استعراض وطلب منتجات د. بايك',
                            ],
                            [
                                'id' => 'maintenance',
                                'title' => 'الصيانة',
                                'description' => 'طلب صيانة أو متابعة حالة الدراجة',
                            ],
                            [
                                'id' => 'inquiries',
                                'title' => 'الاستفسارات',
                                'description' => 'الأسئلة العامة والأسعار والخدمات',
                            ],
                            [
                                'id' => 'employee',
                                'title' => 'التواصل مع موظف',
                                'description' => 'تحويل المحادثة مباشرة إلى أحد الموظفين',
                            ],
                        ],
                    ]],
                ],
            ],
        ], [
            'message_type' => 'interactive',
            'body' => 'قائمة الخدمات: المنتجات، الصيانة، الاستفسارات، والتواصل مع موظف',
            'is_automatic' => true,
        ], null);
    }

    public function downloadMedia(string $mediaId): array
    {
        $this->validateConfig();
        $metadata = Http::withToken(config('whatsapp.access_token'))
            ->acceptJson()->timeout(config('whatsapp.timeout', 20))
            ->get($this->graphEndpoint($mediaId));

        if (! $metadata->successful() || ! $metadata->json('url')) {
            throw new RuntimeException($metadata->json('error.message') ?: 'Unable to read WhatsApp media.');
        }

        $content = Http::withToken(config('whatsapp.access_token'))
            ->timeout(config('whatsapp.timeout', 20))
            ->get($metadata->json('url'));

        if (! $content->successful()) {
            throw new RuntimeException('Unable to download WhatsApp media.');
        }

        return [
            'body' => $content->body(),
            'mime_type' => $metadata->json('mime_type') ?: $content->header('Content-Type') ?: 'application/octet-stream',
            'file_size' => $metadata->json('file_size'),
        ];
    }

    public function businessPhoneNumber(): string
    {
        if (filled(config('whatsapp.display_phone_number'))) {
            return $this->normalizePhone((string) config('whatsapp.display_phone_number'));
        }

        $this->validateConfig();
        return Cache::remember('whatsapp.business_phone_number', now()->addHour(), function () {
            $response = Http::withToken(config('whatsapp.access_token'))->acceptJson()
                ->get($this->graphEndpoint(config('whatsapp.phone_number_id')), [
                    'fields' => 'display_phone_number',
                ]);
            if (! $response->successful() || ! $response->json('display_phone_number')) {
                throw new RuntimeException($response->json('error.message') ?: 'Unable to read WhatsApp business number.');
            }
            return $this->normalizePhone($response->json('display_phone_number'));
        });
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

    public function sendTypingIndicator(string $messageId): array
    {
        $this->validateConfig();
        $response = $this->client()->post($this->endpoint(), [
            'messaging_product' => 'whatsapp',
            'status' => 'read',
            'message_id' => $messageId,
            'typing_indicator' => ['type' => 'text'],
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
            $response = $this->client()->post($this->endpoint(), array_filter(array_merge([
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $phone,
            ], $payload), fn ($value) => $value !== null));
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

    private function graphEndpoint(string $path): string
    {
        return sprintf('https://graph.facebook.com/%s/%s', trim(config('whatsapp.api_version'), '/'), ltrim($path, '/'));
    }

    private function mediaType(?string $mime): string
    {
        return match (true) {
            str_starts_with((string) $mime, 'image/') => 'image',
            str_starts_with((string) $mime, 'audio/') => 'audio',
            str_starts_with((string) $mime, 'video/') => 'video',
            default => 'document',
        };
    }

    private function responseArray(Response $response): array
    {
        return ['successful' => $response->successful(), 'status_code' => $response->status(), 'body' => $response->json() ?: []];
    }
}
