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
 * Ephemeral "user is typing" signal for a chat.
 *
 * Broadcast immediately (not queued) so the indicator feels instant; the
 * receiving client clears it automatically after a few seconds.
 */
class ChatUserTyping implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public Chat $chat,
        public User $user,
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
            'name' => $this->user->full_name ?: ($this->user->email ?? 'Участник'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'UserTyping';
    }
}
