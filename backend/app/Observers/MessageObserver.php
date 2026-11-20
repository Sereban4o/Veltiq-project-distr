<?php

namespace App\Observers;

use App\Models\Message;
use App\Support\ChatSearchIndexer;

/**
 * Keeps the message search index in sync (covers create, edit and delete,
 * including the soft-delete performed by Message::markAsDeleted()).
 */
class MessageObserver
{
    public function saved(Message $message): void
    {
        ChatSearchIndexer::reindex($message);
    }

    public function deleted(Message $message): void
    {
        ChatSearchIndexer::forget((int) $message->id);
    }

    public function forceDeleted(Message $message): void
    {
        ChatSearchIndexer::forget((int) $message->id);
    }
}
