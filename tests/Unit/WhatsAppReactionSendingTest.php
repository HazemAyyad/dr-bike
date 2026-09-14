<?php

namespace Tests\Unit;

use App\Services\WhatsApp\WhatsAppCloudApiService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppReactionSendingTest extends TestCase
{
    public function test_it_sends_a_reaction_to_the_referenced_whatsapp_message(): void
    {
        config([
            'whatsapp.api_version' => 'v23.0',
            'whatsapp.access_token' => 'test-token',
            'whatsapp.phone_number_id' => '123456789',
        ]);
        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'reaction-id']]], 200),
        ]);

        (new WhatsAppCloudApiService)->sendReaction(
            '+970599000000',
            'wamid.original-message',
            '👍'
        );

        Http::assertSent(fn (Request $request) =>
            $request->url() === 'https://graph.facebook.com/v23.0/123456789/messages'
            && $request['messaging_product'] === 'whatsapp'
            && $request['to'] === '970599000000'
            && $request['type'] === 'reaction'
            && $request['reaction'] === [
                'message_id' => 'wamid.original-message',
                'emoji' => '👍',
            ]
        );
    }
}
