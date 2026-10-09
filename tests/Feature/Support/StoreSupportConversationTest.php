<?php

namespace Tests\Feature\Support;

use Tests\TestCase;

class StoreSupportConversationTest extends TestCase
{
    public function test_store_contract_has_owner_scoped_throttled_conversation_endpoints(): void
    {
        $routes = file_get_contents(base_path('routes/api_store.php'));
        $controller = file_get_contents(app_path('Http/Controllers/API/Store/StoreSupportConversationController.php'));

        $this->assertStringContainsString("'/OnlineStore/Support/Conversations'", $routes);
        $this->assertStringContainsString("'throttle:20,1'", $routes);
        $this->assertStringContainsString("'throttle:60,1'", $routes);
        $this->assertStringContainsString("where('requester_user_id', \$actor->getKey())", $controller);
        $this->assertStringContainsString('client_message_id', $controller);
        $this->assertStringContainsString('requester_unread_count', $controller);
    }

    public function test_private_attachments_use_signed_download_contract(): void
    {
        $manager = file_get_contents(app_path('Services/Support/SupportMessageManager.php'));
        $payloads = file_get_contents(app_path('Services/Support/SupportPayloadService.php'));
        $routes = file_get_contents(base_path('routes/api.php'));

        $this->assertStringContainsString("'support/private/'", $manager);
        $this->assertStringContainsString("'local'", $manager);
        $this->assertStringContainsString('temporarySignedRoute', $payloads);
        $this->assertStringContainsString("->middleware('signed')", $routes);
    }
}
