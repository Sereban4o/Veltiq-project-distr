<?php

namespace App\Events;

use App\Models\Chat;
use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A message was pinned to or unpinned from a chat.
 */
class PinUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public Chat $chat;

    public Message $message;

    public bool $pinned;

    public int $pinnedBy;

    public function __construct(Chat $chat, Message $message, bool $pinned, int $pinnedBy)
    {
        $this->chat = $chat;
        $this->message = $message->loadMissing(['sender', 'files.file']);
        $this->pinned = $pinned;
        $this->pinnedBy = $pinnedBy;
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel("chat.{$this->chat->id}")];
    }

    public function broadcastWith(): array
    {
        $sender = $this->message->sender;

        return [
            'chat_id' => $this->chat->id,
            'message_id' => $this->message->id,
            'pinned' => $this->pinned,
            'pinned_by' => $this->pinnedBy,
            'message' => [
                'id' => $this->message->id,
                'chat_id' => $this->message->chat_id,
                'sender_id' => $this->message->sender_id,
                'body' => $this->message->body,
                'type' => $this->message->type,
                'metadata' => $this->message->metadata,
                'parent_id' => $this->message->parent_id,
                'edited_at' => $this->message->edited_at,
                'created_at' => $this->message->created_at,
                'sender' => $sender ? [
                    'id' => $sender->id,
                    'first_name' => $sender->first_name,
                    'last_name' => $sender->last_name,
                    'avatar' => $sender->avatar,
                ] : null,
                'files' => $this->message->files,
            ],
        ];
    }
}
