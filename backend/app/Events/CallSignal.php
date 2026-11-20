<?php

namespace App\Events;

use App\Models\Chat;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * WebRTC signaling for chat calls (offer/answer/ICE/hangup...).
 *
 * Ephemeral (broadcast immediately, never queued) and delivered on the chat's
 * private channel; the client filters by `to`/`from` to pick its own signals.
 */
class CallSignal implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public Chat $chat,
        public User $from,
        public string $type,
        public ?int $to,
        public string $callId,
        public array $payload = [],
    ) {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('chat.' . $this->chat->id)];
    }

    public function broadcastWith(): array
    {
        return [
            'chat_id' => (int) $this->chat->id,
            'from' => (int) $this->from->id,
            'to' => $this->to,
            'type' => $this->type,
            'call_id' => $this->callId,
            'payload' => $this->payload,
            'name' => $this->from->full_name ?: ($this->from->email ?? 'Участник'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'CallSignal';
    }
}
