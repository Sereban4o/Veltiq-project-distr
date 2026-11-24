<?php

namespace App\Extensions\Chat\Jobs;

use App\Jobs\SendNtfyNotification;

use App\Models\Chat;
use App\Models\Message;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class PushChatMessageNotifications implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public int $messageId, public int $chatId)
    {
    }

    public function handle(): void
    {
        $message = Message::with('sender:id,first_name,last_name')->find($this->messageId);
        $chat = Chat::find($this->chatId);

        if (!$message || !$chat) {
            return;
        }

        $sender = $message->sender;
        $senderName = trim((string) ($sender->first_name ?? '') . ' ' . (string) ($sender->last_name ?? ''));
        if ($senderName === '') {
            $senderName = 'Сообщение';
        }

        $body = trim((string) $message->body);
        $preview = $body !== ''
            ? Str::limit($body, 140)
            : match ($message->type) {
                'image' => '📷 Изображение',
                'file' => '📎 Файл',
                default => 'Новое сообщение',
            };

        // Who is writing + where: for DM the sender, for groups/channels "sender · chat".
        $title = $chat->type === 'dm'
            ? $senderName
            : trim($senderName . ($chat->name ? ' · ' . $chat->name : ''));

        if ($title === '') {
            $title = 'Чат';
        }

        $members = $chat->members()->pluck('users.id');

        foreach ($members as $memberId) {
            // Never notify the author about their own message.
            if ((int) $memberId === (int) $message->sender_id) {
                continue;
            }

            SendNtfyNotification::dispatch((int) $memberId, $title, $preview, [], [
                'message_id' => (int) $message->id,
                'chat_id' => (int) $chat->id,
                'chat_type' => $chat->type,
                'chat_name' => $chat->name,
                'sender_id' => (int) $message->sender_id,
            ]);
        }
    }
}
