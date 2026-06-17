<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessShiplyWebhookJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ShiplyWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $secret = (string) config('shiply.webhook_secret', '');
        if ($secret !== '') {
            $incoming = (string) ($request->header('X-Shiply-Webhook-Secret')
                ?? $request->input('secret')
                ?? '');
            if (! hash_equals($secret, $incoming)) {
                Log::warning('shiply.webhook_unauthorized');

                return response()->json(['success' => false], 401);
            }
        }

        $payload = $request->all();
        if (($payload['event'] ?? null) !== 'parcel') {
            return response()->json(['success' => true, 'ignored' => true]);
        }

        ProcessShiplyWebhookJob::dispatch($payload);

        return response()->json(['success' => true]);
    }
}
