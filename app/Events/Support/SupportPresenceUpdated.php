<?php

namespace App\Events\Support;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class SupportPresenceUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $conversationId,
        public readonly array $conversation,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('support.conversation.'.$this->conversationId)];
    }

    public function broadcastAs(): string
    {
        return 'support.presence.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'source' => 'online_store',
            'conversation' => $this->conversation,
        ];
    }
}
