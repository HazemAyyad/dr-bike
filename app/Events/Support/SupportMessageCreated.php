<?php

namespace App\Events\Support;

use App\Models\SupportConversation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class SupportMessageCreated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $conversationId,
        public readonly array $message,
        public readonly array $conversation,
        public readonly string $source,
    ) {}

    public static function fromPayloads(SupportConversation $conversation, array $message, array $conversationPayload): self
    {
        return new self(
            (int) $conversation->getKey(),
            $message,
            $conversationPayload,
            (string) $conversation->source,
        );
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('support.conversation.'.$this->conversationId),
            new PrivateChannel('support.inbox'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'support.message.created';
    }

    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'source' => $this->source,
            'message' => $this->message,
            'conversation' => $this->conversation,
        ];
    }
}
