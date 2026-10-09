<?php

namespace App\Events\Support;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class SupportTypingUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $conversationId,
        public readonly string $source,
        public readonly string $actorType,
        public readonly string $actorName,
        public readonly bool $isTyping,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('support.conversation.'.$this->conversationId)];
    }

    public function broadcastAs(): string
    {
        return 'support.typing';
    }

    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'source' => $this->source,
            'actor_type' => $this->actorType,
            'actor_name' => $this->actorName,
            'is_typing' => $this->isTyping,
            'expires_at' => now()->addSeconds(5)->toIso8601String(),
        ];
    }
}
