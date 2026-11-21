<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Signals that new mail was stored for a user.
 *
 * Broadcast on the existing private `user.{id}` channel. The payload carries no
 * subject/body: the client only needs to know that its mailbox changed.
 */
class MailMessageCreated implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(
        public int $userId,
        public int $accountId,
        public int $messageId,
        public string $folder = 'inbox',
    ) {
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("user.{$this->userId}")];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'account_id' => $this->accountId,
            'message_id' => $this->messageId,
            'folder' => $this->folder,
            'timestamp' => now()->toIso8601String(),
        ];
    }

    public function broadcastAs(): string
    {
        return 'mail.created';
    }
}
