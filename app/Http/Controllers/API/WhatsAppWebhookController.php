<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\WhatsAppCloudApiService;
use App\Services\WhatsApp\WhatsAppIncomingNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WhatsAppWebhookController extends Controller
{
    public function verify(Request $request)
    {
        $mode = $request->query('hub.mode', $request->query('hub_mode'));
        $token = $request->query('hub.verify_token', $request->query('hub_verify_token'));
        $challenge = $request->query('hub.challenge', $request->query('hub_challenge'));
        if ($mode === 'subscribe'
            && filled(config('whatsapp.verify_token'))
            && hash_equals((string) config('whatsapp.verify_token'), (string) $token)) {
            return response((string) $challenge, 200)->header('Content-Type', 'text/plain');
        }
        return response('Invalid verification token', 403);
    }

    public function handle(
        Request $request,
        WhatsAppCloudApiService $service,
        WhatsAppIncomingNotificationService $notificationService
    )
    {
        try {
            foreach ((array) data_get($request->all(), 'entry', []) as $entry) {
                foreach ((array) data_get($entry, 'changes', []) as $change) {
                    $value = data_get($change, 'value', []);
                    $names = collect((array) data_get($value, 'contacts', []))
                        ->mapWithKeys(fn ($contact) => [(string) data_get($contact, 'wa_id') => data_get($contact, 'profile.name')]);

                    foreach ((array) data_get($value, 'messages', []) as $incoming) {
                        $message = $this->saveIncoming($service, $incoming, $names->get((string) data_get($incoming, 'from')));
                        if ($message) {
                            $notificationService->notify($message);
                        }
                    }
                    foreach ((array) data_get($value, 'statuses', []) as $status) {
                        $this->updateStatus($status);
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('WhatsApp webhook processing failed', ['error' => $e->getMessage()]);
        }
        return response()->json(['status' => 'success'], 200);
    }

    private function saveIncoming(WhatsAppCloudApiService $service, array $incoming, ?string $name): ?WhatsAppMessage
    {
        $metaId = data_get($incoming, 'id');
        if (! $metaId || WhatsAppMessage::query()->where('meta_message_id', $metaId)->exists()) {
            return null;
        }
        $phone = $service->normalizePhone((string) data_get($incoming, 'from'));
        $contact = $service->findOrCreateContact($phone, $name);
        $conversation = $service->findOrCreateConversation($phone);
        $type = (string) data_get($incoming, 'type', 'system');
        $allowed = ['text', 'image', 'document', 'audio', 'video', 'location', 'interactive'];
        $type = in_array($type, $allowed, true) ? $type : 'system';
        $body = match ($type) {
            'text' => data_get($incoming, 'text.body'),
            'interactive' => data_get($incoming, 'interactive.button_reply.title') ?: data_get($incoming, 'interactive.list_reply.title'),
            'location' => trim(data_get($incoming, 'location.latitude').' '.data_get($incoming, 'location.longitude')),
            default => data_get($incoming, $type.'.caption') ?: '['.$type.']',
        };
        $timestamp = data_get($incoming, 'timestamp') ? now()->setTimestamp((int) data_get($incoming, 'timestamp')) : now();

        $message = WhatsAppMessage::query()->create([
            'whatsapp_conversation_id' => $conversation->id,
            'whatsapp_contact_id' => $contact->id,
            'phone' => $phone,
            'direction' => 'inbound',
            'message_type' => $type,
            'body' => $body,
            'media_url' => data_get($incoming, $type.'.id'),
            'meta_message_id' => $metaId,
            'meta_status' => 'received',
            'raw_payload' => $incoming,
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

        return $message;
    }

    private function updateStatus(array $status): void
    {
        $metaStatus = (string) data_get($status, 'status');
        if (! in_array($metaStatus, ['sent', 'delivered', 'read', 'failed'], true)) {
            return;
        }
        $message = WhatsAppMessage::query()->where('meta_message_id', data_get($status, 'id'))->first();
        if (! $message) {
            return;
        }
        $rank = ['pending' => 0, 'sent' => 1, 'delivered' => 2, 'read' => 3, 'failed' => 4];
        if ($metaStatus === 'failed' || ($rank[$metaStatus] ?? 0) >= ($rank[$message->status] ?? 0)) {
            $message->update([
                'status' => $metaStatus,
                'meta_status' => $metaStatus,
                'raw_payload' => array_merge($message->raw_payload ?: [], ['status_update' => $status]),
                'error_message' => $metaStatus === 'failed' ? data_get($status, 'errors.0.title') : null,
            ]);
        }
    }
}
