<?php

namespace Tests\Feature\Support;

use App\Events\Support\SupportMessageCreated;
use App\Events\Support\SupportTypingUpdated;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
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

    public function test_typing_event_is_immediate_and_conversation_scoped(): void
    {
        $this->assertTrue(is_subclass_of(SupportTypingUpdated::class, ShouldBroadcastNow::class));
        $event = file_get_contents(app_path('Events/Support/SupportTypingUpdated.php'));
        $routes = file_get_contents(base_path('routes/api.php'));

        $this->assertStringContainsString("new PrivateChannel('support.conversation.'", $event);
        $this->assertStringContainsString("return 'support.typing'", $event);
        $this->assertStringContainsString("'/support/conversations/{conversation}/typing'", $routes);
    }

    public function test_admin_messages_use_shared_idempotent_message_manager(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/API/SupportConversationController.php'));

        $this->assertStringContainsString("'client_message_id' => ['nullable', 'uuid']", $controller);
        $this->assertStringContainsString('$this->supportMessages->create(', $controller);
        $this->assertStringContainsString('$existing ? 200 : 201', $controller);
    }
}
