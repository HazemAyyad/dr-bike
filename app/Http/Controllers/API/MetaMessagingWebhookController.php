<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\Meta\MetaMessagingService;
use App\Services\Meta\SocialIncomingNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MetaMessagingWebhookController extends Controller
{
    public function verify(Request $request)
    {
        $mode = $request->query('hub.mode', $request->query('hub_mode'));
        $token = $request->query('hub.verify_token', $request->query('hub_verify_token'));
        $challenge = $request->query('hub.challenge', $request->query('hub_challenge'));
        Log::info('Meta messaging webhook verify request', [
            'mode' => $mode,
            'token_matches' => filled(config('meta_messaging.verify_token'))
                && hash_equals((string) config('meta_messaging.verify_token'), (string) $token),
            'has_challenge' => filled($challenge),
        ]);

        if ($mode === 'subscribe'
            && filled(config('meta_messaging.verify_token'))
            && hash_equals((string) config('meta_messaging.verify_token'), (string) $token)) {
            return response((string) $challenge, 200)->header('Content-Type', 'text/plain');
        }

        return response('Invalid verification token', 403);
    }

    public function handle(
        Request $request,
        MetaMessagingService $service,
        SocialIncomingNotificationService $notificationService
    )
    {
        try {
            Log::info('Meta messaging webhook received', [
                'object' => data_get($request->all(), 'object'),
                'entry_count' => count((array) data_get($request->all(), 'entry', [])),
                'payload_keys' => array_keys($request->all()),
            ]);

            $object = (string) data_get($request->all(), 'object');
            $channel = match ($object) {
                'instagram' => 'instagram',
                'page' => 'facebook',
                default => null,
            };

            if (! $channel) {
                return response()->json(['status' => 'ignored'], 200);
            }

            foreach ((array) data_get($request->all(), 'entry', []) as $entry) {
                foreach ((array) data_get($entry, 'messaging', []) as $event) {
                    try {
                        $message = $service->saveIncoming($channel, $event);
                        if ($message) {
                            $notificationService->notify($message);
                        }
                    } catch (\Throwable $e) {
                        Log::warning('Meta messaging webhook event failed', [
                            'channel' => $channel,
                            'sender_id' => data_get($event, 'sender.id'),
                            'recipient_id' => data_get($event, 'recipient.id'),
                            'message_id' => data_get($event, 'message.mid'),
                            'event_keys' => array_keys((array) $event),
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Meta messaging webhook processing failed', [
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json(['status' => 'success'], 200);
    }
}
