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
 * A chat member advanced their read position. Broadcast immediately so the
 * sender sees "read" ticks without a page reload.
 */
class ChatRead implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public Chat $chat,
        public User $user,
        public int $lastReadMessageId,
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
            'user_id' => (int) $this->user->id,
            'last_read_message_id' => $this->lastReadMessageId,
        ];
    }

    public function broadcastAs(): string
    {
        return 'ChatRead';
    }
}
