<?php

namespace Tests\Feature\Support;

use App\Events\Support\SupportMessageCreated;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Tests\TestCase;

class SupportInboxContractTest extends TestCase
{
    public function test_store_inbox_uses_dedicated_permission_and_filters(): void
    {
        $access = file_get_contents(app_path('Services/Support/SupportAccessService.php'));
        $controller = file_get_contents(app_path('Http/Controllers/API/SupportConversationController.php'));

        $this->assertStringContainsString("STORE_PERMISSION = 'Online Store Support'", $access);
        $this->assertStringContainsString("filled('source')", $controller);
        $this->assertStringContainsString("boolean('needs_reply')", $controller);
        $this->assertStringContainsString("'unassigned'", $controller);
        $this->assertStringContainsString('assign_to_me', $controller);
    }

    public function test_message_event_broadcasts_to_private_conversation_and_inbox_channels(): void
    {
        $this->assertTrue(is_subclass_of(SupportMessageCreated::class, ShouldBroadcast::class));
        $event = file_get_contents(app_path('Events/Support/SupportMessageCreated.php'));
        $channels = file_get_contents(base_path('routes/channels.php'));

        $this->assertStringContainsString("support.conversation.'", $event);
        $this->assertStringContainsString("new PrivateChannel('support.inbox')", $event);
        $this->assertStringContainsString("Broadcast::channel('support.conversation.{conversationId}'", $channels);
        $this->assertStringContainsString("Broadcast::channel('support.inbox'", $channels);
    }
}
