<?php

namespace App\Events\Support;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class SupportConversationRead implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly int $conversationId, public readonly array $conversation) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('support.conversation.'.$this->conversationId),
            new PrivateChannel('support.inbox'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'support.conversation.read';
    }
}
